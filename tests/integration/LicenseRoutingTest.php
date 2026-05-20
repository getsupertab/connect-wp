<?php
/**
 * Integration tests covering the /license.xml route and the activation
 * redirect flow. Demonstrates the three techniques needed to test this
 * plugin against real WordPress:
 *
 *   1. Driving a request through parse_request via WP_UnitTestCase::go_to()
 *      (requires pretty permalinks; the test framework defaults to plain).
 *   2. Triggering register_activation_hook via the activate_{basename} action.
 *   3. Capturing wp_safe_redirect() by throwing from the wp_redirect filter,
 *      invoking the handler directly so unrelated admin_init callbacks
 *      (e.g. nocache_headers) don't interfere with header()-sensitive flows.
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Admin\Settings_Page;
use Supertab_Connect\Settings;
use WP_UnitTestCase;

class LicenseRoutingTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		delete_option( 'supertab_connect_merchant_api_key' );
		delete_option( 'supertab_connect_website_urn' );
		delete_option( 'supertab_connect_bot_protection_enabled' );
		delete_option( 'supertab_connect_active_paths' );
		delete_transient( 'supertab_connect_activating' );
		delete_transient( 'supertab_connect_license_xml' );

		// Pretty permalinks are required for WP::parse_request() to populate
		// $wp->request from the URL path. The test framework defaults to
		// plain permalinks ("").
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules();
	}

	/**
	 * Proves the routing layer reaches the plugin's parse_request handler.
	 *
	 * Stubs cannot simulate WP's rewrite pipeline, which is the layer that
	 * has historically broken — see the `wp rewrite flush --hard` workaround
	 * in .wp-env.json. URN is intentionally empty so the handler short-circuits
	 * before reaching its `exit;` call.
	 */
	public function test_license_xml_request_resolves_to_parse_request(): void {
		$this->go_to( home_url( '/license.xml' ) );

		$this->assertSame( 'license.xml', $GLOBALS['wp']->request );
	}

	/**
	 * Proves register_activation_hook fires the transient set used by the
	 * post-activation redirect.
	 */
	public function test_activation_sets_redirect_transient(): void {
		do_action( 'activate_' . plugin_basename( SUPERTAB_CONNECT_PLUGIN_FILE ) );

		$this->assertTrue( (bool) get_transient( 'supertab_connect_activating' ) );
	}

	/**
	 * Proves the post-activation redirect targets the settings page.
	 *
	 * Invokes handle_activation_redirect() directly rather than firing
	 * do_action('admin_init'): WP core registers several admin_init callbacks
	 * (e.g. nocache_headers) that call header() and would emit "headers
	 * already sent" warnings — output started during the test framework's
	 * bootstrap — racing our throw-from-filter.
	 */
	public function test_handle_activation_redirect_targets_settings_page(): void {
		set_transient( 'supertab_connect_activating', true, 30 );

		add_filter(
			'wp_redirect',
			static function ( string $location ): void {
				throw new \RuntimeException( 'REDIRECT:' . $location );
			},
			5
		);

		$page = new Settings_Page( new Settings() );

		try {
			$page->handle_activation_redirect();
			$this->fail( 'Expected wp_safe_redirect() to fire but no redirect occurred.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'REDIRECT:', $e->getMessage() );
			$this->assertStringContainsString( 'page=supertab-connect-settings', $e->getMessage() );
		}
	}

	/**
	 * Proves a configured site is NOT redirected after activation, and that
	 * the activating transient is cleared regardless.
	 */
	public function test_handle_activation_redirect_skips_when_credentials_set(): void {
		set_transient( 'supertab_connect_activating', true, 30 );
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		update_option( 'supertab_connect_merchant_api_key', 'test-key' );

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected ): void {
				$redirected = true;
				throw new \RuntimeException( 'UNEXPECTED_REDIRECT:' . $location );
			},
			5
		);

		$page = new Settings_Page( new Settings() );
		$page->handle_activation_redirect();

		$this->assertFalse( $redirected, 'Configured site should not be redirected.' );
		$this->assertFalse( get_transient( 'supertab_connect_activating' ), 'Activating transient should be cleared.' );
	}
}
