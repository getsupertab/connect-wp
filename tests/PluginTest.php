<?php
declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Plugin;

class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	private function should_buffer_analytics(): bool {
		$method = new \ReflectionMethod( Plugin::class, 'should_buffer_analytics' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null );
	}

	public function test_buffers_analytics_when_object_cache_present(): void {
		global $wp_test_ext_object_cache;
		$wp_test_ext_object_cache = true;

		$this->assertTrue( $this->should_buffer_analytics() );
	}

	public function test_does_not_buffer_without_persistent_object_cache(): void {
		global $wp_test_ext_object_cache;
		$wp_test_ext_object_cache = false;

		$this->assertFalse( $this->should_buffer_analytics() );
	}
}
