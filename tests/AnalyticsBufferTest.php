<?php
declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Analytics_Buffer;
use Supertab_Connect\Analytics_Config;

class AnalyticsBufferTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	/** Config: window 10, shards 2, cap 3, ttl 100. */
	private function config(): Analytics_Config {
		return new Analytics_Config( 10, 2, 3, 60, 3, 0, 2, 100, 100 );
	}

	/** Buffer pinned to a fixed time and shard for deterministic keys. */
	private function buffer( int $now, int $shard ): Analytics_Buffer {
		return new class( $this->config(), $now, $shard ) extends Analytics_Buffer {
			public function __construct( Analytics_Config $config, private int $test_now, private int $test_shard ) {
				parent::__construct( $config );
			}
			protected function now(): int {
				return $this->test_now;
			}
			protected function pick_shard(): int {
				return $this->test_shard;
			}
		};
	}

	public function test_capture_writes_event_under_sequenced_key(): void {
		global $wp_test_object_cache;

		// now=125, window=10 => bucket=12; shard pinned to 1.
		$this->buffer( 125, 1 )->capture( array( 'request_id' => 'r1' ) );

		$group = Analytics_Buffer::GROUP;
		$this->assertSame( 1, $wp_test_object_cache[ $group ][ Analytics_Buffer::counter_key( 12, 1 ) ] );
		$stored = json_decode( $wp_test_object_cache[ $group ][ Analytics_Buffer::event_key( 12, 1, 1 ) ], true );
		$this->assertSame( array( 'request_id' => 'r1' ), $stored );
	}

	public function test_capture_assigns_incrementing_sequences(): void {
		global $wp_test_object_cache;

		$buffer = $this->buffer( 125, 0 );
		$buffer->capture( array( 'request_id' => 'a' ) );
		$buffer->capture( array( 'request_id' => 'b' ) );

		$group = Analytics_Buffer::GROUP;
		$this->assertSame( 2, $wp_test_object_cache[ $group ][ Analytics_Buffer::counter_key( 12, 0 ) ] );
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 12, 0, 1 ), $wp_test_object_cache[ $group ] );
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 12, 0, 2 ), $wp_test_object_cache[ $group ] );
	}

	public function test_capture_sheds_events_over_the_hard_cap(): void {
		global $wp_test_object_cache;

		$buffer = $this->buffer( 125, 0 );
		// cap_per_shard = 3: writes 1..3 stored, 4th shed.
		for ( $i = 0; $i < 4; $i++ ) {
			$buffer->capture( array( 'n' => $i ) );
		}

		$group = Analytics_Buffer::GROUP;
		$this->assertSame( 4, $wp_test_object_cache[ $group ][ Analytics_Buffer::counter_key( 12, 0 ) ], 'counter still increments' );
		$this->assertArrayHasKey( Analytics_Buffer::event_key( 12, 0, 3 ), $wp_test_object_cache[ $group ] );
		$this->assertArrayNotHasKey( Analytics_Buffer::event_key( 12, 0, 4 ), $wp_test_object_cache[ $group ], 'over-cap event shed' );
	}
}
