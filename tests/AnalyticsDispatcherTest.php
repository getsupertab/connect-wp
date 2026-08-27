<?php
/**
 * Tests for Analytics_Dispatcher buffering and inline fallback.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab_Connect\Analytics_Dispatcher;
use Supertab_Connect\Analytics_Queue_Table;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\WP_Http_Client;

class AnalyticsDispatcherTest extends TestCase {

	private const FLUSH_HOOK  = 'supertab_connect_flush_analytics';
	private const LEGACY_HOOK = 'supertab_connect_emit_analytics';

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	/**
	 * In-memory stand-in for the queue table.
	 *
	 * @return Analytics_Queue_Table
	 */
	private function make_fake_table(): Analytics_Queue_Table {
		return new class() extends Analytics_Queue_Table {
			/** @var list<string> */
			public array $rows      = array();
			public bool $insert_ok  = true;
			public bool $full       = false;
			/** @var list<int> */
			public array $claim_calls   = array();
			public int $install_calls   = 0;

			public function install(): void {
				++$this->install_calls;
			}

			public function insert( string $payload ): bool {
				if ( ! $this->insert_ok ) {
					return false;
				}
				$this->rows[] = $payload;
				return true;
			}

			public function is_full( int $max_rows ): bool {
				return $this->full;
			}

			public function claim_batch( int $limit ): array {
				$this->claim_calls[] = $limit;
				return array_splice( $this->rows, 0, $limit );
			}
		};
	}

	private function make_dispatcher( ?Analytics_Queue_Table $table = null ): Analytics_Dispatcher {
		return new Analytics_Dispatcher( new Settings(), new WP_Http_Client(), $table ?? $this->make_fake_table() );
	}

	/**
	 * Fire every callback register() deferred to init, as WordPress would.
	 */
	private function fire_init_callbacks(): void {
		global $wp_test_actions;

		foreach ( $wp_test_actions as $action ) {
			if ( 'init' === $action['hook'] ) {
				call_user_func( $action['callback'] );
			}
		}
	}

	/**
	 * Build a dispatcher that reports Action Scheduler as unavailable.
	 */
	private function make_dispatcher_without_action_scheduler( Analytics_Queue_Table $table ): Analytics_Dispatcher {
		return new class( new Settings(), new WP_Http_Client(), $table ) extends Analytics_Dispatcher {
			protected function action_scheduler_available(): bool {
				return false;
			}
		};
	}

	public function test_enqueue_buffers_event_as_json_row(): void {
		global $wp_test_http_calls;

		$table = $this->make_fake_table();

		$this->make_dispatcher( $table )->enqueue( array( 'request_id' => 'req-1' ) );

		$this->assertSame( array( '{"request_id":"req-1"}' ), $table->rows );
		$this->assertSame( array(), $wp_test_http_calls, 'Buffering must not trigger an HTTP call.' );
	}

	public function test_enqueue_drops_event_when_buffer_full(): void {
		global $wp_test_http_calls;

		$table       = $this->make_fake_table();
		$table->full = true;

		$this->make_dispatcher( $table )->enqueue( array( 'request_id' => 'req-overflow' ) );

		$this->assertSame( array(), $table->rows, 'Event must be dropped at the row cap.' );
		$this->assertSame( array(), $wp_test_http_calls, 'A capped buffer must not fall back to inline delivery.' );
	}

	public function test_enqueue_falls_back_inline_when_insert_fails(): void {
		global $wp_test_http_calls;

		update_option( 'supertab_connect_merchant_api_key', 'key-inline' );

		$table            = $this->make_fake_table();
		$table->insert_ok = false;

		$this->make_dispatcher( $table )->enqueue( array( 'request_id' => 'req-9' ) );

		$this->assertCount( 1, $wp_test_http_calls );
		$call = $wp_test_http_calls[0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( SUPERTAB_CONNECT_ANALYTICS_BASE_URL . '/ingest/events', $call['url'] );

		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( 'req-9', $body['request_id'] );
	}

	public function test_dispatch_posts_classified_event_to_relay(): void {
		global $wp_test_http_calls;

		update_option( 'supertab_connect_merchant_api_key', 'key-abc' );

		$this->make_dispatcher()->dispatch(
			array(
				'request_id'    => 'req-123',
				'final_action'  => 'block',
				'token_outcome' => 'valid',
			)
		);

		$this->assertCount( 1, $wp_test_http_calls );
		$call = $wp_test_http_calls[0];

		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( SUPERTAB_CONNECT_ANALYTICS_BASE_URL . '/ingest/events', $call['url'] );
		$this->assertSame( 'Bearer key-abc', $call['args']['headers']['Authorization'] );

		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( 'req-123', $body['request_id'] );
		$this->assertSame( 'block', $body['final_action'] );
		$this->assertSame( 'valid', $body['token_outcome'] );
		$this->assertSame( AnalyticsEvent::SCHEMA_VERSION, $body['schema_version'] );
	}

	public function test_dispatch_swallows_malformed_event_data(): void {
		global $wp_test_http_calls;

		// An object without __toString() makes AnalyticsEvent::fromArray()'s
		// (string) cast throw a fatal \Error; dispatch() must swallow it.
		$this->make_dispatcher()->dispatch( array( 'timestamp' => new \stdClass() ) );

		$reached = true;

		$this->assertTrue( $reached, 'dispatch() returned without throwing on malformed data.' );
		$this->assertSame( array(), $wp_test_http_calls, 'No relay POST should occur when rehydration fails.' );
	}

	public function test_clear_scheduled_clears_both_hooks_in_both_backends(): void {
		global $wp_test_unscheduled_hooks, $wp_test_as_unschedule_calls;

		Analytics_Dispatcher::clear_scheduled();

		$this->assertSame( array( self::FLUSH_HOOK, self::LEGACY_HOOK ), $wp_test_unscheduled_hooks );

		$this->assertCount( 2, $wp_test_as_unschedule_calls );
		$this->assertSame( self::FLUSH_HOOK, $wp_test_as_unschedule_calls[0]['hook'] );
		$this->assertSame( self::LEGACY_HOOK, $wp_test_as_unschedule_calls[1]['hook'] );
		// Hook-only clearing (empty args/group) hits the bulk cancel-by-hook path.
		$this->assertSame( array(), $wp_test_as_unschedule_calls[0]['args'] );
		$this->assertSame( '', $wp_test_as_unschedule_calls[0]['group'] );
	}

	public function test_flush_without_rows_makes_no_request(): void {
		global $wp_test_http_calls;

		$table = $this->make_fake_table();

		$this->make_dispatcher( $table )->flush();

		$this->assertSame( array(), $wp_test_http_calls );
		$this->assertSame( array( 500 ), $table->claim_calls, 'Exactly one cheap claim on an empty buffer.' );
	}

	public function test_flush_posts_batch_array_with_auth(): void {
		global $wp_test_http_calls;

		update_option( 'supertab_connect_merchant_api_key', 'key-batch' );

		$table       = $this->make_fake_table();
		$table->rows = array( '{"request_id":"req-1"}', '{"request_id":"req-2"}' );

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 1, $wp_test_http_calls );
		$call = $wp_test_http_calls[0];
		$this->assertSame( 'POST', $call['method'] );
		$this->assertSame( SUPERTAB_CONNECT_ANALYTICS_BASE_URL . '/ingest/events', $call['url'] );
		$this->assertSame( 'Bearer key-batch', $call['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $call['args']['headers']['Content-Type'] );

		$body = json_decode( $call['args']['body'], true );
		$this->assertSame(
			array(
				array( 'request_id' => 'req-1' ),
				array( 'request_id' => 'req-2' ),
			),
			$body,
			'Body must be a JSON array of event objects.'
		);

		$this->assertSame( array(), $table->rows, 'Claimed rows are deleted.' );
	}

	public function test_flush_skips_malformed_rows(): void {
		global $wp_test_http_calls;

		$table       = $this->make_fake_table();
		$table->rows = array( 'not-json', '{"request_id":"req-ok"}' );

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 1, $wp_test_http_calls );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( array( array( 'request_id' => 'req-ok' ) ), $body );
	}

	public function test_flush_posts_nothing_when_all_rows_malformed(): void {
		global $wp_test_http_calls;

		$table       = $this->make_fake_table();
		$table->rows = array( 'nope', '[]broken' );

		$this->make_dispatcher( $table )->flush();

		$this->assertSame( array(), $wp_test_http_calls );
		$this->assertSame( array(), $table->rows, 'Malformed rows are still consumed.' );
	}

	public function test_flush_stops_after_max_batches(): void {
		global $wp_test_http_calls;

		$table = $this->make_fake_table();
		for ( $i = 0; $i < 5500; $i++ ) {
			$table->rows[] = '{"request_id":"req-' . $i . '"}';
		}

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 10, $wp_test_http_calls, '10 batches of 500, then stop.' );
		$this->assertSame( array_fill( 0, 10, 500 ), $table->claim_calls );
		$this->assertCount( 500, $table->rows, 'Remainder waits for the next run.' );
	}

	public function test_flush_makes_second_empty_claim_on_exact_batch_boundary(): void {
		global $wp_test_http_calls;

		$table = $this->make_fake_table();
		for ( $i = 0; $i < 500; $i++ ) {
			$table->rows[] = '{"request_id":"req-' . $i . '"}';
		}

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 1, $wp_test_http_calls, 'One full batch POSTed.' );
		$this->assertSame( array( 500, 500 ), $table->claim_calls, 'A full batch triggers one more (empty) claim.' );
		$this->assertSame( array(), $table->rows );
	}

	public function test_flush_swallows_http_errors(): void {
		$table       = $this->make_fake_table();
		$table->rows = array( '{"request_id":"req-x"}' );

		$throwing_client = new class() implements \Supertab\Connect\Http\HttpClientInterface {
			public function get( string $url, array $headers = array() ): array {
				throw new \RuntimeException( 'boom' );
			}
			public function post( string $url, string $body, array $headers = array() ): array {
				throw new \RuntimeException( 'boom' );
			}
		};

		$dispatcher = new Analytics_Dispatcher( new Settings(), $throwing_client, $table );
		$dispatcher->flush();

		$this->assertSame( array(), $table->rows, 'Deliver-once: rows are gone even when the POST fails.' );
	}

	public function test_flush_drops_batch_without_retry_on_non_2xx(): void {
		global $wp_test_http_calls, $wp_test_http_response;

		$wp_test_http_response = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{}',
		);

		$table       = $this->make_fake_table();
		$table->rows = array( '{"request_id":"req-fail"}' );

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 1, $wp_test_http_calls, 'Exactly one POST — no retry on non-2xx.' );
		$this->assertSame( array(), $table->rows, 'Deliver-once: rows stay consumed on non-2xx.' );
	}

	public function test_flush_tolerates_partial_rejection_response(): void {
		global $wp_test_http_calls, $wp_test_http_response;

		$wp_test_http_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"accepted_count":1,"rejected_count":1,"message":"partial"}',
		);

		$table       = $this->make_fake_table();
		$table->rows = array( '{"request_id":"req-a"}', '{"request_id":"req-b"}' );

		$this->make_dispatcher( $table )->flush();

		$this->assertCount( 1, $wp_test_http_calls, 'Rejected events are never re-sent.' );
		$this->assertSame( array(), $table->rows );
	}

	public function test_register_defers_schedule_check_to_init(): void {
		global $wp_test_doing_cron, $wp_test_actions, $wp_test_as_recurring_calls, $wp_test_recurring_events;

		$wp_test_doing_cron = true;

		$this->make_dispatcher()->register();

		// register() runs at plugins_loaded, before Action Scheduler's data
		// store initializes (init priority 1) — as_*() calls made that early
		// silently no-op, so no scheduling API may be touched yet.
		$this->assertSame( array(), $wp_test_as_recurring_calls, 'No AS call at registration time.' );
		$this->assertSame( array(), $wp_test_recurring_events, 'No WP-Cron schedule at registration time.' );

		$init_hooks = array_values(
			array_filter( $wp_test_actions, static fn ( array $a ): bool => 'init' === $a['hook'] )
		);
		$this->assertCount( 1, $init_hooks, 'The schedule check must be deferred to init.' );

		// Firing the deferred callback performs the actual scheduling.
		call_user_func( $init_hooks[0]['callback'] );

		$this->assertCount( 1, $wp_test_as_recurring_calls );
		$this->assertSame( self::FLUSH_HOOK, $wp_test_as_recurring_calls[0]['hook'] );
	}

	public function test_register_schedules_recurring_via_action_scheduler_in_cron_context(): void {
		global $wp_test_doing_cron, $wp_test_as_recurring_calls, $wp_test_recurring_events;

		$wp_test_doing_cron = true;

		$this->make_dispatcher()->register();
		$this->fire_init_callbacks();

		$this->assertCount( 1, $wp_test_as_recurring_calls );
		$call = $wp_test_as_recurring_calls[0];
		$this->assertSame( self::FLUSH_HOOK, $call['hook'] );
		$this->assertSame( HOUR_IN_SECONDS, $call['interval'] );
		$this->assertSame( 'supertab-connect', $call['group'] );
		$this->assertSame( array(), $wp_test_recurring_events, 'No duplicate WP-Cron schedule.' );
	}

	public function test_register_skips_scheduling_when_as_action_exists(): void {
		global $wp_test_doing_cron, $wp_test_as_has_scheduled, $wp_test_as_recurring_calls;

		$wp_test_doing_cron       = true;
		$wp_test_as_has_scheduled = true;

		$this->make_dispatcher()->register();
		$this->fire_init_callbacks();

		$this->assertSame( array(), $wp_test_as_recurring_calls );
	}

	public function test_register_falls_back_to_wp_cron_recurring(): void {
		global $wp_test_doing_cron, $wp_test_recurring_events;

		$wp_test_doing_cron = true;

		$this->make_dispatcher_without_action_scheduler( $this->make_fake_table() )->register();
		$this->fire_init_callbacks();

		$this->assertCount( 1, $wp_test_recurring_events );
		$this->assertSame( self::FLUSH_HOOK, $wp_test_recurring_events[0]['hook'] );
		$this->assertSame( 'hourly', $wp_test_recurring_events[0]['recurrence'] );
	}

	public function test_register_skips_wp_cron_when_already_scheduled(): void {
		global $wp_test_doing_cron, $wp_test_next_scheduled, $wp_test_recurring_events;

		$wp_test_doing_cron     = true;
		$wp_test_next_scheduled = time() + 100;

		$this->make_dispatcher_without_action_scheduler( $this->make_fake_table() )->register();
		$this->fire_init_callbacks();

		$this->assertSame( array(), $wp_test_recurring_events );
	}

	public function test_register_migrates_wp_cron_schedule_to_action_scheduler(): void {
		global $wp_test_doing_cron, $wp_test_next_scheduled, $wp_test_cleared_hooks, $wp_test_as_recurring_calls;

		$wp_test_doing_cron     = true;
		$wp_test_next_scheduled = time() + 100;

		$this->make_dispatcher()->register();
		$this->fire_init_callbacks();

		// The stale WP-Cron recurrence is cleared so both backends never fire.
		$this->assertCount( 1, $wp_test_cleared_hooks );
		$this->assertSame( self::FLUSH_HOOK, $wp_test_cleared_hooks[0]['hook'] );
		$this->assertCount( 1, $wp_test_as_recurring_calls );
	}

	public function test_register_installs_table_in_admin_context(): void {
		global $wp_test_is_admin;

		$wp_test_is_admin = true;
		$table            = $this->make_fake_table();

		$this->make_dispatcher( $table )->register();

		$this->assertSame( 1, $table->install_calls );
	}

	public function test_register_swallows_install_failure(): void {
		global $wp_test_doing_cron, $wp_test_as_recurring_calls;

		$wp_test_doing_cron = true;

		$table = new class() extends Analytics_Queue_Table {
			public function install(): void {
				throw new \RuntimeException( 'db down' );
			}
		};

		$this->make_dispatcher( $table )->register();
		$this->fire_init_callbacks();

		$this->assertCount( 1, $wp_test_as_recurring_calls, 'Scheduling still proceeds after install failure.' );
	}

	public function test_register_does_no_schedule_work_on_front_end(): void {
		global $wp_test_as_recurring_calls, $wp_test_recurring_events;

		$table = $this->make_fake_table();

		$this->make_dispatcher( $table )->register();

		$this->assertSame( 0, $table->install_calls );
		$this->assertSame( array(), $wp_test_as_recurring_calls );
		$this->assertSame( array(), $wp_test_recurring_events );

		global $wp_test_actions;
		$init_hooks = array_filter( $wp_test_actions, static fn ( array $a ): bool => 'init' === $a['hook'] );
		$this->assertSame( array(), $init_hooks, 'No deferred schedule check on the front end.' );
	}
}
