<?php
/**
 * Tests for the Settings class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Settings;
use Yoast\PHPUnitPolyfills\Polyfills\AssertionRenames;

class SettingsTest extends TestCase {

	use AssertionRenames;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	public function test_get_merchant_api_key_returns_empty_string_when_not_set(): void {
		$settings = new Settings();
		$this->assertSame( '', $settings->get_merchant_api_key() );
	}

	public function test_get_website_urn_returns_empty_string_when_not_set(): void {
		$settings = new Settings();
		$this->assertSame( '', $settings->get_website_urn() );
	}

	public function test_has_credentials_returns_false_when_not_set(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->has_credentials() );
	}

	public function test_save_stores_credentials(): void {
		$settings = new Settings();
		$settings->save( 'test-api-key', 'urn:supertab:merchant-system:123' );

		$this->assertSame( 'test-api-key', $settings->get_merchant_api_key() );
		$this->assertSame( 'urn:supertab:merchant-system:123', $settings->get_website_urn() );
	}

	public function test_has_credentials_returns_true_when_both_set(): void {
		$settings = new Settings();
		$settings->save( 'test-api-key', 'urn:supertab:merchant-system:123' );

		$this->assertTrue( $settings->has_credentials() );
	}

	public function test_has_credentials_returns_false_when_only_api_key_set(): void {
		$settings = new Settings();

		global $wp_test_options;
		$wp_test_options['supertab_connect_merchant_api_key'] = 'test-api-key';

		$this->assertFalse( $settings->has_credentials() );
	}

	public function test_has_credentials_returns_false_when_only_urn_set(): void {
		$settings = new Settings();

		global $wp_test_options;
		$wp_test_options['supertab_connect_website_urn'] = 'urn:supertab:merchant-system:123';

		$this->assertFalse( $settings->has_credentials() );
	}

	public function test_delete_removes_credentials(): void {
		$settings = new Settings();
		$settings->save( 'test-api-key', 'urn:supertab:merchant-system:123' );
		$settings->delete();

		$this->assertSame( '', $settings->get_merchant_api_key() );
		$this->assertSame( '', $settings->get_website_urn() );
		$this->assertFalse( $settings->has_credentials() );
	}

	public function test_save_invalidates_license_xml_cache(): void {
		global $wp_test_transients;
		$wp_test_transients['supertab_connect_license_xml'] = '<xml>cached</xml>';

		$settings = new Settings();
		$settings->save( 'new-key', 'new-urn' );

		$this->assertArrayNotHasKey( 'supertab_connect_license_xml', $wp_test_transients );
	}

	public function test_delete_invalidates_license_xml_cache(): void {
		global $wp_test_transients;
		$wp_test_transients['supertab_connect_license_xml'] = '<xml>cached</xml>';

		$settings = new Settings();
		$settings->delete();

		$this->assertArrayNotHasKey( 'supertab_connect_license_xml', $wp_test_transients );
	}

	public function test_is_bot_protection_enabled_returns_false_by_default(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->is_bot_protection_enabled() );
	}

	public function test_set_bot_protection_enabled_stores_value(): void {
		$settings = new Settings();
		$settings->set_bot_protection_enabled( true );

		$this->assertTrue( $settings->is_bot_protection_enabled() );
	}

	public function test_set_bot_protection_enabled_can_disable(): void {
		$settings = new Settings();
		$settings->set_bot_protection_enabled( true );
		$settings->set_bot_protection_enabled( false );

		$this->assertFalse( $settings->is_bot_protection_enabled() );
	}

	public function test_delete_removes_bot_protection_setting(): void {
		$settings = new Settings();
		$settings->set_bot_protection_enabled( true );
		$settings->delete();

		$this->assertFalse( $settings->is_bot_protection_enabled() );
	}

	public function test_save_overwrites_existing_credentials(): void {
		$settings = new Settings();
		$settings->save( 'old-key', 'old-urn' );
		$settings->save( 'new-key', 'new-urn' );

		$this->assertSame( 'new-key', $settings->get_merchant_api_key() );
		$this->assertSame( 'new-urn', $settings->get_website_urn() );
	}

	public function test_has_website_urn_returns_false_when_not_set(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->has_website_urn() );
	}

	public function test_has_website_urn_returns_true_when_set(): void {
		$settings = new Settings();
		$settings->save_website_urn( 'urn:supertab:merchant-system:123' );

		$this->assertTrue( $settings->has_website_urn() );
	}

	public function test_has_merchant_api_key_returns_false_when_not_set(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->has_merchant_api_key() );
	}

	public function test_has_merchant_api_key_returns_true_when_set(): void {
		$settings = new Settings();
		$settings->save_merchant_api_key( 'test-api-key' );

		$this->assertTrue( $settings->has_merchant_api_key() );
	}

	public function test_save_website_urn_stores_urn(): void {
		$settings = new Settings();
		$settings->save_website_urn( 'urn:supertab:merchant-system:456' );

		$this->assertSame( 'urn:supertab:merchant-system:456', $settings->get_website_urn() );
	}

	public function test_save_website_urn_invalidates_license_cache(): void {
		global $wp_test_transients;
		$wp_test_transients['supertab_connect_license_xml'] = '<xml>cached</xml>';

		$settings = new Settings();
		$settings->save_website_urn( 'urn:supertab:merchant-system:789' );

		$this->assertArrayNotHasKey( 'supertab_connect_license_xml', $wp_test_transients );
	}

	public function test_save_merchant_api_key_stores_key(): void {
		$settings = new Settings();
		$settings->save_merchant_api_key( 'my-api-key' );

		$this->assertSame( 'my-api-key', $settings->get_merchant_api_key() );
	}

	public function test_save_merchant_api_key_does_not_affect_urn(): void {
		$settings = new Settings();
		$settings->save_website_urn( 'urn:supertab:merchant-system:123' );
		$settings->save_merchant_api_key( 'my-api-key' );

		$this->assertSame( 'urn:supertab:merchant-system:123', $settings->get_website_urn() );
	}

	public function test_save_website_urn_does_not_affect_api_key(): void {
		$settings = new Settings();
		$settings->save_merchant_api_key( 'my-api-key' );
		$settings->save_website_urn( 'urn:supertab:merchant-system:123' );

		$this->assertSame( 'my-api-key', $settings->get_merchant_api_key() );
	}

	public function test_get_active_paths_returns_default_wildcard(): void {
		$settings = new Settings();
		$this->assertSame( array( '*' ), $settings->get_active_paths() );
	}

	public function test_set_active_paths_stores_paths(): void {
		$settings = new Settings();
		$settings->set_active_paths( array( 'blog/*', 'pricing' ) );

		$this->assertSame( array( 'blog/*', 'pricing' ), $settings->get_active_paths() );
	}

	public function test_delete_removes_active_paths(): void {
		$settings = new Settings();
		$settings->set_active_paths( array( 'blog/*' ) );
		$settings->delete();

		$this->assertSame( array( '*' ), $settings->get_active_paths() );
	}
}
