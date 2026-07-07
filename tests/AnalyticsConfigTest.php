<?php
declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Analytics_Config;

class AnalyticsConfigTest extends TestCase {

	public function test_from_constants_applies_defaults(): void {
		$config = Analytics_Config::from_constants();

		$this->assertSame( 10, $config->window );
		$this->assertSame( 16, $config->shards );
		$this->assertSame( 1000, $config->cap_per_shard );
		$this->assertSame( 60, $config->drain_interval );
		$this->assertSame( 18, $config->lookback );
		$this->assertSame( 1, $config->settle );
		$this->assertSame( 100, $config->batch );
		$this->assertSame( 20, $config->max_run );
		// ttl = lookback * window + 20 = 18 * 10 + 20.
		$this->assertSame( 200, $config->ttl );
	}

	public function test_constructor_accepts_explicit_values(): void {
		$config = new Analytics_Config( 5, 2, 3, 30, 4, 0, 2, 50, 40 );

		$this->assertSame( 5, $config->window );
		$this->assertSame( 2, $config->shards );
		$this->assertSame( 0, $config->settle );
		$this->assertSame( 40, $config->ttl );
	}
}
