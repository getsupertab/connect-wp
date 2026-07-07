<?php
declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Analytics_Buffer;
use Supertab_Connect\Analytics_Config;
use Supertab_Connect\Analytics_Drain;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\WP_Http_Client;

class AnalyticsDrainTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
		update_option( 'supertab_connect_merchant_api_key', 'key-xyz' );
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	/** Config: window 10, shards 2, cap 3, lookback 3, settle 0, batch 2, max_run 100, ttl 100. */
	private function config(): Analytics_Config {
		return new Analytics_Config( 10, 2, 3, 60, 3, 0, 2, 100, 100 );
	}

	private function drain( int $now ): Analytics_Drain {
		return new class( new Settings(), new WP_Http_Client(), $this->config(), $now ) extends Analytics_Drain {
			public function __construct( Settings $s, WP_Http_Client $h, Analytics_Config $c, private int $test_now ) {
				parent::__construct( $s, $h, $c );
			}
			protected function now(): int {
				return $this->test_now;
			}
		};
	}

	/** Config with a tiny budget: window 10, shards 2, cap 3, lookback 3, settle 0, batch 1, max_run 5, ttl 100. */
	private function budget_config(): Analytics_Config {
		return new Analytics_Config( 10, 2, 3, 60, 3, 0, 1, 5, 100 );
	}

	/**
	 * Drain whose now() replays a queue of timestamps, repeating the last once exhausted.
	 *
	 * @param array<int, int> $ticks Timestamps to return on successive now() calls.
	 */
	private function drain_with_clock( array $ticks ): Analytics_Drain {
		return new class( new Settings(), new WP_Http_Client(), $this->budget_config(), $ticks ) extends Analytics_Drain {
			/** @var array<int, int> */
			private array $ticks;
			public function __construct( Settings $s, WP_Http_Client $h, Analytics_Config $c, array $ticks ) {
				parent::__construct( $s, $h, $c );
				$this->ticks = $ticks;
			}
			protected function now(): int {
				return count( $this->ticks ) > 1 ? (int) array_shift( $this->ticks ) : (int) $this->ticks[0];
			}
		};
	}

	/** Seed one event directly into the buffer's cache keys. */
	private function seed( int $bucket, int $shard, int $seq, array $payload ): void {
		wp_cache_add( Analytics_Buffer::counter_key( $bucket, $shard ), 0, Analytics_Buffer::GROUP );
		wp_cache_incr( Analytics_Buffer::counter_key( $bucket, $shard ), 1, Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( $bucket, $shard, $seq ), wp_json_encode( $payload ), Analytics_Buffer::GROUP );
	}

	public function test_drain_delivers_closed_bucket_and_deletes_keys(): void {
		global $wp_test_http_calls, $wp_test_object_cache;

		// now=125 => current bucket 12; closed buckets scanned: 9..11 (settle 0 => newest 11).
		$this->seed( 11, 0, 1, array( 'request_id' => 'r-closed' ) );

		$this->drain( 125 )->drain();

		$this->assertCount( 1, $wp_test_http_calls );
		$this->assertSame( 'POST', $wp_test_http_calls[0]['method'] );
		$this->assertSame( SUPERTAB_CONNECT_API_BASE_URL . '/ingest/events', $wp_test_http_calls[0]['url'] );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( 'r-closed', $body['request_id'] );

		// Keys deleted after the attempt.
		$this->assertArrayNotHasKey( Analytics_Buffer::event_key( 11, 0, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] ?? array() );
		$this->assertArrayNotHasKey( Analytics_Buffer::counter_key( 11, 0 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] ?? array() );
	}

	public function test_drain_ignores_current_bucket(): void {
		global $wp_test_http_calls;

		// now=125 => current bucket 12 must NOT be drained.
		$this->seed( 12, 0, 1, array( 'request_id' => 'r-current' ) );

		$this->drain( 125 )->drain();

		$this->assertSame( array(), $wp_test_http_calls );
	}

	public function test_drain_reads_at_most_cap_per_shard(): void {
		global $wp_test_http_calls;

		// Counter says 5 but cap is 3: only seqs 1..3 exist and are delivered.
		wp_cache_set( Analytics_Buffer::counter_key( 11, 0 ), 5, Analytics_Buffer::GROUP );
		for ( $seq = 1; $seq <= 3; $seq++ ) {
			wp_cache_set( Analytics_Buffer::event_key( 11, 0, $seq ), wp_json_encode( array( 'request_id' => 's' . $seq ) ), Analytics_Buffer::GROUP );
		}

		$this->drain( 125 )->drain();

		$this->assertCount( 3, $wp_test_http_calls );
	}

	public function test_drain_skips_missing_payloads_fail_open(): void {
		global $wp_test_http_calls;

		// Counter=2 but only seq 2 has a payload (seq 1 evicted).
		wp_cache_set( Analytics_Buffer::counter_key( 11, 0 ), 2, Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( 11, 0, 2 ), wp_json_encode( array( 'request_id' => 'only-2' ) ), Analytics_Buffer::GROUP );

		$this->drain( 125 )->drain();

		$this->assertCount( 1, $wp_test_http_calls );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( 'only-2', $body['request_id'] );
	}

	public function test_drain_stops_when_budget_exhausted_mid_bucket(): void {
		global $wp_test_http_calls, $wp_test_object_cache;

		// Bucket 11 (closed), both shards seeded with one event each.
		wp_cache_set( Analytics_Buffer::counter_key( 11, 0 ), 1, Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( 11, 0, 1 ), wp_json_encode( array( 'request_id' => 'a' ) ), Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::counter_key( 11, 1 ), 1, Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( 11, 1, 1 ), wp_json_encode( array( 'request_id' => 'b' ) ), Analytics_Buffer::GROUP );

		// now() call sequence for this fixed config (window 10, shards 2, lookback 3, settle 0,
		// batch 1, max_run 5): #1 current=intdiv(125,10)=12; #2 deadline=125+5=130;
		// #3..#6 the per-shard deadline check at the top of the loop for the four empty
		// bucket/shard slots (9,0) (9,1) (10,0) (10,1), all 125<130; #7 the deadline check
		// for bucket 11 shard 0 (125<130) -> counter/event read -> deliver() is called with
		// bucket 11 shard 0's one item; inside deliver() the event is emitted and its key
		// deleted, then the per-item deadline check runs -> #8 returns 130 (>=deadline), but
		// it was the last (only) item in that shard so deliver() returns false and the
		// now-empty shard's counter key is deleted too; #9 the deadline check for bucket 11
		// shard 1 returns 130 (>=deadline) -> drain() returns immediately. Shard 1 of
		// bucket 11 is never reached: neither its event key nor its counter key are touched.
		$ticks = array( 125, 125, 125, 125, 125, 125, 125, 130 );

		$this->drain_with_clock( $ticks )->drain();

		// Exactly one delivery: shard 0's 'a'.
		$this->assertCount( 1, $wp_test_http_calls );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( 'a', $body['request_id'] );

		// Shard 0 was fully delivered: both its event key and counter key are gone.
		$this->assertArrayNotHasKey( Analytics_Buffer::event_key( 11, 0, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] ?? array() );
		$this->assertArrayNotHasKey( Analytics_Buffer::counter_key( 11, 0 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] ?? array() );

		// Shard 1 was never attempted: its event key AND counter key both survive, so the
		// shard re-drains on the next run. This is the key defer guarantee of the new design.
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 11, 1, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] );
		$this->assertArrayHasKey( Analytics_Buffer::counter_key( 11, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] );
	}

	public function test_drain_defers_last_event_in_shard_when_deadline_hits_mid_delivery(): void {
		global $wp_test_http_calls, $wp_test_object_cache;

		// Bucket 11 (closed), a single shard with two events: 'a' is attempted and
		// delivered, then the deadline is reached before 'b' can be attempted.
		wp_cache_set( Analytics_Buffer::counter_key( 11, 0 ), 2, Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( 11, 0, 1 ), wp_json_encode( array( 'request_id' => 'a' ) ), Analytics_Buffer::GROUP );
		wp_cache_set( Analytics_Buffer::event_key( 11, 0, 2 ), wp_json_encode( array( 'request_id' => 'b' ) ), Analytics_Buffer::GROUP );

		// now() call sequence (window 10, shards 2, lookback 3, settle 0, batch 1, max_run 5):
		// #1 current=intdiv(125,10)=12; #2 deadline=125+5=130; #3..#6 empty slots
		// (9,0) (9,1) (10,0) (10,1) all 125<130; #7 bucket 11 shard 0 deadline check
		// 125<130 -> counter=2, both events read -> deliver() called with ['a','b'];
		// inside deliver(): i=0 ('a') emits + deletes key, then #8 the per-item deadline
		// check returns 130 (>=deadline) and i(0) < last(1) is true -> deliver() returns
		// true immediately, leaving 'b' untouched. drain() then returns without deleting
		// the shard's counter key.
		$ticks = array( 125, 125, 125, 125, 125, 125, 125, 130 );

		$this->drain_with_clock( $ticks )->drain();

		// Only 'a' was attempted.
		$this->assertCount( 1, $wp_test_http_calls );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( 'a', $body['request_id'] );

		// 'a' was attempted, so its key is gone regardless of delivery outcome.
		$this->assertArrayNotHasKey( Analytics_Buffer::event_key( 11, 0, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] ?? array() );

		// 'b' was never attempted: its key AND the shard's counter key both survive.
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 11, 0, 2 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] );
		$this->assertArrayHasKey( Analytics_Buffer::counter_key( 11, 0 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] );
	}

	public function test_register_schedules_recurring_action_when_absent(): void {
		global $wp_test_as_recurring_calls, $wp_test_as_next_scheduled;

		$wp_test_as_next_scheduled = false;
		$this->drain( 1000 )->register();

		$this->assertCount( 1, $wp_test_as_recurring_calls );
		$this->assertSame( Analytics_Drain::HOOK, $wp_test_as_recurring_calls[0]['hook'] );
		$this->assertSame( 60, $wp_test_as_recurring_calls[0]['interval'] );
		$this->assertSame( Analytics_Buffer::GROUP, $wp_test_as_recurring_calls[0]['group'] );
	}

	public function test_register_is_idempotent_when_already_scheduled(): void {
		global $wp_test_as_recurring_calls, $wp_test_as_next_scheduled;

		$wp_test_as_next_scheduled = 123456; // already scheduled.
		$this->drain( 1000 )->register();

		$this->assertSame( array(), $wp_test_as_recurring_calls );
	}

	public function test_register_falls_back_to_wp_cron_without_action_scheduler(): void {
		global $wp_test_scheduled_events, $wp_test_wp_next_scheduled;

		$wp_test_wp_next_scheduled = false;
		$drain = new class( new Settings(), new WP_Http_Client(), $this->config(), 1000 ) extends Analytics_Drain {
			public function __construct( Settings $s, WP_Http_Client $h, Analytics_Config $c, private int $test_now ) {
				parent::__construct( $s, $h, $c );
			}
			protected function now(): int {
				return $this->test_now;
			}
			protected function action_scheduler_available(): bool {
				return false;
			}
		};
		$drain->register();

		$this->assertCount( 1, $wp_test_scheduled_events );
		$this->assertSame( Analytics_Drain::HOOK, $wp_test_scheduled_events[0]['hook'] );
		$this->assertSame( Analytics_Drain::CRON_SCHEDULE, $wp_test_scheduled_events[0]['recurrence'] );
	}

	public function test_add_cron_interval_registers_drain_interval(): void {
		$schedules = $this->drain( 1000 )->add_cron_interval( array() );

		$this->assertArrayHasKey( Analytics_Drain::CRON_SCHEDULE, $schedules );
		$this->assertSame( 60, $schedules[ Analytics_Drain::CRON_SCHEDULE ]['interval'] );
	}

	public function test_clear_scheduled_clears_drain_and_legacy_hooks(): void {
		global $wp_test_unscheduled_hooks, $wp_test_as_unschedule_calls;

		Analytics_Drain::clear_scheduled();

		$this->assertSame(
			array( Analytics_Drain::HOOK, Analytics_Drain::LEGACY_HOOK ),
			$wp_test_unscheduled_hooks
		);
		$this->assertCount( 2, $wp_test_as_unschedule_calls );
		$this->assertSame( Analytics_Drain::HOOK, $wp_test_as_unschedule_calls[0]['hook'] );
		$this->assertSame( Analytics_Drain::LEGACY_HOOK, $wp_test_as_unschedule_calls[1]['hook'] );
	}
}
