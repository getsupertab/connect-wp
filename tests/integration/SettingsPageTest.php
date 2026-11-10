<?php
/**
 * Integration tests for the settings page views.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Admin\Settings_Page;
use Supertab_Connect\Robots_Txt_Handler;
use Supertab_Connect\Settings;
use WP_UnitTestCase;

class SettingsPageTest extends WP_UnitTestCase {

	private Settings $settings;

	private Settings_Page $page;

	protected function setUp(): void {
		parent::setUp();
		catch_redirects();
		$this->settings = new Settings();
		$this->page     = new Settings_Page( $this->settings, new Robots_Txt_Handler( $this->settings ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->use_home( 'https://palmspringspost.com' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function use_home( string $home ): void {
		remove_all_filters( 'pre_option_home' );
		remove_all_filters( 'pre_option_siteurl' );
		add_filter( 'pre_option_home', static fn() => $home );
		add_filter( 'pre_option_siteurl', static fn() => $home );
	}

	/**
	 * Render the page with $query as the request's query string.
	 *
	 * @param array<string, string> $query Query arguments.
	 */
	private function render( array $query = array() ): string {
		$_GET = $query;
		ob_start();
		$this->page->render_page();
		return (string) ob_get_clean();
	}

	private function connected_via_connect( string $base_url = 'https://palmspringspost.com' ): void {
		$this->settings->save( 'the-key', 'urn:supertab:website:42' );
		$this->settings->save_connection( 'connect', 'Palm Springs Post', $base_url );
	}

	/**
	 * Submit the settings form and return the settings-page query it redirects to.
	 *
	 * @param array<string, mixed> $fields Posted fields.
	 * @return array<string, string>
	 */
	private function submit( array $fields ): array {
		$_POST = array_merge( array( 'supertab_connect_nonce' => wp_create_nonce( 'supertab_connect_settings' ) ), $fields );
		try {
			$this->page->handle_form_submission();
		} catch ( Redirect_Caught $redirect ) {
			return query_of( $redirect->location );
		}
		$this->fail( 'The form did not redirect.' );
	}

	public function test_a_new_site_is_offered_connect_website_and_manual_credentials(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="action" value="supertab_connect_start"', $html );
		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([^"]+)"/', $html );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $nonce );
		$this->assertNotFalse( wp_verify_nonce( $nonce[1], 'supertab_connect_start' ) );
		$this->assertStringContainsString( 'Connect my website', $html );
		$this->assertStringContainsString( 'You&#039;ll create a Supertab account or sign in, then come straight back.', $html );
		$this->assertStringContainsString( 'Enter credentials manually', $html );
		$this->assertStringContainsString( 'name="website_urn"', $html );
		$this->assertStringNotContainsString( 'supertab_connect_disconnect', $html );
	}

	public function test_a_connected_site_shows_its_website_and_disconnect(): void {
		$this->connected_via_connect();

		$html = $this->render();

		$this->assertStringContainsString( 'Connected to <strong>Palm Springs Post</strong> (palmspringspost.com)', $html );
		$this->assertStringContainsString( 'name="action" value="supertab_connect_disconnect"', $html );
		$this->assertStringContainsString( 'urn:supertab:website:42', $html );
		$this->assertStringNotContainsString( 'name="website_urn"', $html );
		$this->assertStringNotContainsString( 'Change API key', $html );
		$this->assertStringNotContainsString( 'Reconnect', $html );
	}

	public function test_a_subdirectory_install_is_not_flagged_as_moved(): void {
		$this->use_home( 'https://palmspringspost.com/news' );
		$this->connected_via_connect();

		$this->assertStringNotContainsString( 'Reconnect', $this->render() );
	}

	public function test_a_site_whose_address_changed_is_offered_a_reconnect(): void {
		$this->use_home( 'https://new.example.com' );
		$this->connected_via_connect();

		$html = $this->render();

		$this->assertStringContainsString(
			'Your site&#039;s address is now https://new.example.com, but it&#039;s connected to Supertab as https://palmspringspost.com. Reconnect to update it.',
			$html
		);
		$this->assertStringContainsString( 'Reconnect', $html );
		$this->assertStringContainsString( 'name="action" value="supertab_connect_start"', $html );
	}

	public function test_a_manually_connected_site_shows_its_urn_and_change_api_key(): void {
		$this->settings->save( 'pasted-key', 'urn:supertab:website:manual' );

		$html = $this->render();

		$this->assertStringContainsString( 'Connected with Website URN <code>urn:supertab:website:manual</code>', $html );
		$this->assertStringContainsString( 'Change API key', $html );
		$this->assertStringContainsString( 'name="action" value="supertab_connect_disconnect"', $html );
	}

	/**
	 * @dataProvider messages
	 */
	public function test_each_result_shows_its_message( array $query, string $message ): void {
		$this->assertStringContainsString( $message, $this->render( $query ) );
	}

	public static function messages(): array {
		return array(
			'connected'           => array( array( 'connect' => 'connected' ), 'Connected' ),
			'cancelled'           => array( array( 'connect' => 'cancelled' ), 'Connection cancelled. Click Connect my website whenever you&#039;re ready.' ),
			'disconnected'        => array( array( 'connect' => 'disconnected' ), 'Disconnected from Supertab.' ),
			'expired code'        => array( array( 'connect_error' => 'invalid_code' ), 'This connection link expired or was already used. Click Connect my website to try again.' ),
			'unknown state'       => array( array( 'connect_error' => 'invalid_state' ), 'This connection link expired or was already used. Click Connect my website to try again.' ),
			'other user'          => array( array( 'connect_error' => 'wrong_user' ), 'Supertab couldn&#039;t connect this site. Click Connect my website to try again, or enter your credentials manually.' ),
			'different site'      => array( array( 'connect_error' => 'site_mismatch' ), 'Supertab couldn&#039;t connect this site. Click Connect my website to try again, or enter your credentials manually.' ),
			'failed'              => array( array( 'connect_error' => 'failed' ), 'Supertab couldn&#039;t connect this site. Click Connect my website to try again, or enter your credentials manually.' ),
			'unreachable'         => array( array( 'connect_error' => 'unreachable' ), 'Couldn&#039;t reach Supertab. Try again, or ask your hosting provider to allow connections to Supertab.' ),
			'admin on other host' => array( array( 'connect_error' => 'admin_host_mismatch' ), 'For security, your WordPress admin needs to be on the same address as your site (palmspringspost.com) to connect. Enter your credentials manually instead.' ),
			'admin on HTTP'       => array( array( 'connect_error' => 'insecure_admin' ), 'For security, your WordPress admin needs to be on HTTPS to connect. Enter your credentials manually instead.' ),
		);
	}

	public function test_an_unreachable_disconnect_offers_disconnect_anyway(): void {
		$this->connected_via_connect();

		$html = $this->render( array( 'disconnect' => 'unreachable' ) );

		$this->assertStringContainsString( 'Couldn&#039;t reach Supertab. Disconnect anyway?', $html );
		$this->assertStringContainsString( 'name="force" value="1"', $html );
		$this->assertStringContainsString( 'Disconnect anyway', $html );
	}

	public function test_saving_cap_settings_keeps_the_urn(): void {
		$this->connected_via_connect();

		$query = $this->submit( array( 'bot_protection_enabled' => '1' ) );

		$this->assertSame( 'success', $query['setup'] );
		$this->assertSame( 'urn:supertab:website:42', $this->settings->get_website_urn() );
		$this->assertTrue( $this->settings->is_bot_protection_enabled() );
		$this->assertSame( 'connect', $this->settings->get_connection_method() );
	}

	public function test_saving_manual_credentials_marks_the_connection_manual(): void {
		$this->connected_via_connect();

		$this->submit(
			array(
				'website_urn'      => 'urn:supertab:website:manual',
				'merchant_api_key' => 'pasted-key',
			)
		);

		$this->assertSame( 'urn:supertab:website:manual', $this->settings->get_website_urn() );
		$this->assertSame( 'pasted-key', $this->settings->get_merchant_api_key() );
		$this->assertSame( 'manual', $this->settings->get_connection_method() );
	}

	public function test_a_key_entered_by_hand_on_a_connected_site_is_never_revoked(): void {
		$this->connected_via_connect();

		$this->submit( array( 'merchant_api_key' => 'pasted-key' ) );

		$this->assertSame( 'pasted-key', $this->settings->get_merchant_api_key() );
		$this->assertSame( 'manual', $this->settings->get_connection_method() );
	}

	public function test_a_user_who_cannot_manage_the_plugin_sees_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render() );
	}
}
