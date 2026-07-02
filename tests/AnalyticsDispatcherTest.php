<?php
/**
 * Tests for Analytics_Dispatcher queue selection and dispatch.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab_Connect\Analytics_Dispatcher;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\WP_Http_Client;

class AnalyticsDispatcherTest extends TestCase {

	private const HOOK = 'supertab_connect_emit_analytics';

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	/**
	 * Build a dispatcher backed by the WP HTTP client stub.
	 */
	private function make_dispatcher(): Analytics_Dispatcher {
		return new Analytics_Dispatcher( new Settings(), new WP_Http_Client() );
	}

	/**
	 * Build a dispatcher that reports Action Scheduler as unavailable.
	 */
	private function make_dispatcher_without_action_scheduler(): Analytics_Dispatcher {
		return new class( new Settings(), new WP_Http_Client() ) extends Analytics_Dispatcher {
			protected function action_scheduler_available(): bool {
				return false;
			}
		};
	}

	public function test_enqueue_uses_action_scheduler_when_available(): void {
		global $wp_test_as_enqueue_calls, $wp_test_scheduled_events;

		$this->make_dispatcher()->enqueue( array( 'request_id' => 'req-async' ) );

		$this->assertCount( 1, $wp_test_as_enqueue_calls );
		$this->assertSame( self::HOOK, $wp_test_as_enqueue_calls[0]['hook'] );
		$this->assertSame( array( array( 'request_id' => 'req-async' ) ), $wp_test_as_enqueue_calls[0]['args'] );
		$this->assertSame( 'supertab-connect', $wp_test_as_enqueue_calls[0]['group'] );
		$this->assertSame( array(), $wp_test_scheduled_events );
	}

	public function test_enqueue_falls_back_to_wp_cron_when_action_scheduler_absent(): void {
		global $wp_test_scheduled_events, $wp_test_http_calls;

		$this->make_dispatcher_without_action_scheduler()->enqueue( array( 'request_id' => 'req-cron' ) );

		$this->assertCount( 1, $wp_test_scheduled_events );
		$this->assertSame( self::HOOK, $wp_test_scheduled_events[0]['hook'] );
		$this->assertSame( array( array( 'request_id' => 'req-cron' ) ), $wp_test_scheduled_events[0]['args'] );
		$this->assertSame( array(), $wp_test_http_calls );
	}

	public function test_enqueue_emits_inline_when_scheduling_fails(): void {
		global $wp_test_schedule_result, $wp_test_http_calls;

		update_option( 'supertab_connect_merchant_api_key', 'key-inline' );
		$wp_test_schedule_result = false;

		$this->make_dispatcher_without_action_scheduler()->enqueue( array( 'request_id' => 'req-inline' ) );

		$this->assertCount( 1, $wp_test_http_calls );
		$this->assertSame( 'POST', $wp_test_http_calls[0]['method'] );
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
		$this->assertSame( SUPERTAB_CONNECT_API_BASE_URL . '/ingest/events', $call['url'] );
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
}
