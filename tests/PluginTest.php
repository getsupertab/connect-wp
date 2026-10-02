<?php
/**
 * Tests for the Plugin class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab_Connect\Plugin;
use Supertab_Connect\Settings;

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

	public function test_request_analytics_transport_posts_with_short_timeout(): void {
		global $wp_test_http_calls;

		update_option( 'supertab_connect_merchant_api_key', 'key-req' );

		$method = ( new \ReflectionClass( Plugin::class ) )->getMethod( 'request_analytics_transport' );
		$transport = $method->invoke( null, new Settings() );

		// No fastcgi_finish_request() under the CLI, so the deferred wrapper emits inline.
		$transport->emit( AnalyticsEvent::fromArray( array( 'request_id' => 'req-1' ) ) );

		$this->assertCount( 1, $wp_test_http_calls );
		$this->assertSame( SUPERTAB_CONNECT_ANALYTICS_BASE_URL . '/ingest/events', $wp_test_http_calls[0]['url'] );
		$this->assertSame( 1, $wp_test_http_calls[0]['args']['timeout'], 'The visitor-request POST must not inherit the 5s WordPress default.' );
	}
}
