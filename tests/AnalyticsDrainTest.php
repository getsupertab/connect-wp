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
		// #3..#6 empty buckets 9 & 10 (2 shard checks each) all 125<130; #7 bucket 11 shard 0
		// check 125<130 -> read+flush 'a'; #8 after-flush check returns 130 (>=deadline) -> break.
		// Shard 1 of bucket 11 is never reached.
		$ticks = array( 125, 125, 125, 125, 125, 125, 125, 130 );

		$this->drain_with_clock( $ticks )->drain();

		// Exactly one delivery: shard 0's 'a'.
		$this->assertCount( 1, $wp_test_http_calls );
		$body = json_decode( $wp_test_http_calls[0]['args']['body'], true );
		$this->assertSame( 'a', $body['request_id'] );

		// Shard 1's event was never read or deleted — it survives in the cache.
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 11, 1, 1 ), $wp_test_object_cache[ Analytics_Buffer::GROUP ] );
	}
}
