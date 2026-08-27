<?php
/**
 * Tests for the Plugin class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Enum\EnforcementMode;
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

	public function test_map_enforcement_mode_value_accepts_current_values(): void {
		$this->assertSame( EnforcementMode::DISABLED, Plugin::map_enforcement_mode_value( 'disabled' ) );
		$this->assertSame( EnforcementMode::OBSERVE, Plugin::map_enforcement_mode_value( 'observe' ) );
		$this->assertSame( EnforcementMode::ENFORCE, Plugin::map_enforcement_mode_value( 'enforce' ) );
	}

	public function test_map_enforcement_mode_value_maps_legacy_soft_to_observe(): void {
		$this->assertSame( EnforcementMode::OBSERVE, Plugin::map_enforcement_mode_value( 'soft' ) );
	}

	public function test_map_enforcement_mode_value_maps_legacy_strict_to_enforce(): void {
		$this->assertSame( EnforcementMode::ENFORCE, Plugin::map_enforcement_mode_value( 'strict' ) );
	}

	public function test_map_enforcement_mode_value_returns_null_for_unknown_value(): void {
		$this->assertNull( Plugin::map_enforcement_mode_value( 'aggressive' ) );
		$this->assertNull( Plugin::map_enforcement_mode_value( '' ) );
	}

	public function test_get_enforcement_mode_defaults_to_observe(): void {
		$this->assertSame( EnforcementMode::OBSERVE, Plugin::get_enforcement_mode() );
	}
}
