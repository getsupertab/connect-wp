<?php
/**
 * Tests for the WP_Transient_Cache class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Utils\WP_Transient_Cache;
use Yoast\PHPUnitPolyfills\Polyfills\AssertionRenames;

class WPTransientCacheTest extends TestCase {

	use AssertionRenames;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	public function test_get_returns_null_when_not_set(): void {
		$cache = new WP_Transient_Cache();
		$this->assertNull( $cache->get( 'nonexistent_key' ) );
	}

	public function test_get_returns_cached_value(): void {
		global $wp_test_transients;
		$wp_test_transients['test_key'] = '{"keys":[]}';

		$cache = new WP_Transient_Cache();
		$this->assertSame( '{"keys":[]}', $cache->get( 'test_key' ) );
	}

	public function test_set_stores_value(): void {
		$cache = new WP_Transient_Cache();
		$cache->set( 'test_key', '{"keys":[]}', 3600 );

		$this->assertSame( '{"keys":[]}', $cache->get( 'test_key' ) );
	}

	public function test_set_passes_ttl_to_transient(): void {
		global $wp_test_transients;

		$cache = new WP_Transient_Cache();
		$cache->set( 'test_key', 'value', 172800 );

		$this->assertSame( 'value', $wp_test_transients['test_key'] );
	}

	public function test_set_overwrites_existing_value(): void {
		$cache = new WP_Transient_Cache();
		$cache->set( 'test_key', 'old_value', 3600 );
		$cache->set( 'test_key', 'new_value', 3600 );

		$this->assertSame( 'new_value', $cache->get( 'test_key' ) );
	}
}
