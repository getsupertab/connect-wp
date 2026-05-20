<?php
/**
 * Integration tests covering the /license.xml route and the activation
 * redirect flow. Demonstrates the three techniques needed to test this
 * plugin against real WordPress:
 *
 *   1. Driving a request through parse_request via WP_UnitTestCase::go_to()
 *   2. Triggering register_activation_hook via the activate_{basename} action
 *   3. Capturing wp_safe_redirect() by throwing from the wp_redirect filter
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

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
	 * Proves admin_init redirects to the settings page on first dashboard
	 * load after activation. Uses the standard "throw from wp_redirect"
	 * trick to unwind before wp_safe_redirect() calls exit.
	 */
	public function test_admin_init_redirects_to_settings_page_after_activation(): void {
		set_transient( 'supertab_connect_activating', true, 30 );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): void {
				throw new \RuntimeException( 'REDIRECT:' . $location );
			},
			5
		);

		try {
			do_action( 'admin_init' );
			$this->fail( 'Expected wp_safe_redirect() to fire but no redirect occurred.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'REDIRECT:', $e->getMessage() );
			$this->assertStringContainsString( 'page=supertab-connect-settings', $e->getMessage() );
		}
	}

	/**
	 * Proves a configured site is NOT redirected after activation.
	 */
	public function test_admin_init_does_not_redirect_when_credentials_already_set(): void {
		set_transient( 'supertab_connect_activating', true, 30 );
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		update_option( 'supertab_connect_merchant_api_key', 'test-key' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'dashboard' );

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected ): void {
				$redirected = true;
				throw new \RuntimeException( 'UNEXPECTED_REDIRECT:' . $location );
			},
			5
		);

		do_action( 'admin_init' );

		$this->assertFalse( $redirected, 'Configured site should not be redirected.' );
		$this->assertFalse( get_transient( 'supertab_connect_activating' ), 'Activating transient should be cleared.' );
	}
}
