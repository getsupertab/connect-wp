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
use Supertab_Connect\Saved_Robots_Txt_Handler;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\WP_Http_Client;
use WP_UnitTestCase;

class SettingsPageTest extends WP_UnitTestCase {

	private const ROBOTS_FILE = "User-agent: *\nDisallow: /private/\n";

	private Settings $settings;

	private Settings_Page $page;

	/**
	 * A physical robots.txt a test wrote, removed in tearDown().
	 *
	 * @var string
	 */
	private string $physical_robots_txt = '';

	protected function setUp(): void {
		parent::setUp();
		catch_redirects();
		$this->settings = new Settings();
		$this->page     = new Settings_Page(
			$this->settings,
			new Robots_Txt_Handler( $this->settings ),
			new Saved_Robots_Txt_Handler( $this->settings, 'https://api.test', new WP_Http_Client( 3 ) )
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->use_home( 'https://palmspringspost.com' );
	}

	protected function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		if ( '' !== $this->physical_robots_txt && file_exists( $this->physical_robots_txt ) ) {
			unlink( $this->physical_robots_txt );
		}
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
		$this->assertStringContainsString( 'Already have a Website URN and API key?', $html );
		$this->assertStringContainsString( 'Enter them manually', $html );
		$this->assertStringContainsString( 'name="website_urn"', $html );
		$this->assertStringNotContainsString( 'supertab_connect_disconnect', $html );
	}

	public function test_a_new_site_sees_the_connect_steps(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'assets/images/supertab-connect-logo.svg', $html );
		$this->assertStringContainsString( 'Connect your website', $html );
		$this->assertStringContainsString( 'You need a Supertab Connect account to continue. We&#039;ll handle the connection:', $html );
		$this->assertMatchesRegularExpression(
			'#<ol class="supertab-steps">\s*'
				. '<li>Sign in or create an account\.</li>\s*'
				. '<li>Approve the connection in the Supertab Connect portal\.</li>\s*'
				. '<li>Come back here, your site will be connected and ready\.</li>\s*'
				. '</ol>#',
			$html
		);
	}

	public function test_a_new_site_gets_the_resource_links_under_the_card_instead_of_a_sidebar(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( '<h2 class="hndle">Resources</h2>', $html );
		$this->assertStringNotContainsString( 'Publish a machine-readable license file on your website', $html );
		$this->assertMatchesRegularExpression( '#<ul class="supertab-onboarding__links">.*Documentation.*Supertab Connect Dashboard.*</ul>#s', $html );
	}

	public function test_a_connected_site_keeps_the_intro_and_the_resources_sidebar(): void {
		$this->connected_via_connect();

		$html = $this->render();

		$this->assertStringContainsString( '<h2 class="hndle">Resources</h2>', $html );
		$this->assertStringContainsString( 'Publish a machine-readable license file on your website', $html );
		$this->assertStringNotContainsString( 'supertab-onboarding__links', $html );
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

	public function test_coming_back_connected_shows_a_success_card(): void {
		$this->connected_via_connect();

		$html = $this->render( array( 'connect' => 'connected' ) );

		$this->assertStringContainsString( 'assets/images/supertab-connect-logo.svg', $html );
		$this->assertStringContainsString( 'You&#039;re connected', $html );
		$this->assertStringContainsString( '<strong>Palm Springs Post</strong> (palmspringspost.com) is now connected to Supertab Connect.', $html );
	}

	public function test_continue_on_the_success_card_opens_the_settings_page(): void {
		$this->connected_via_connect();

		$html = $this->render( array( 'connect' => 'connected' ) );

		$this->assertMatchesRegularExpression(
			'#<a href="' . preg_quote( esc_url( admin_url( 'options-general.php?page=supertab-connect-settings' ) ), '#' ) . '"[^>]*>\s*Continue to settings\s*</a>#',
			$html
		);
	}

	public function test_the_success_card_replaces_the_settings_until_continue(): void {
		$this->connected_via_connect();

		$html = $this->render( array( 'connect' => 'connected' ) );

		$this->assertStringNotContainsString( 'notice-success', $html );
		$this->assertStringNotContainsString( 'Publish a machine-readable license file on your website', $html );
		$this->assertStringNotContainsString( 'supertab_connect_disconnect', $html );
		$this->assertStringNotContainsString( 'name="bot_protection_enabled"', $html );
		$this->assertStringNotContainsString( '<h2 class="hndle">Resources</h2>', $html );
	}

	public function test_a_disconnected_site_ignores_a_stale_connected_link(): void {
		$html = $this->render( array( 'connect' => 'connected' ) );

		$this->assertStringNotContainsString( 'You&#039;re connected', $html );
		$this->assertStringNotContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Connect your website', $html );
	}

	public function test_a_manually_connected_site_ignores_a_stale_connected_link(): void {
		$this->settings->save( 'pasted-key', 'urn:supertab:website:manual' );

		$html = $this->render( array( 'connect' => 'connected' ) );

		$this->assertStringNotContainsString( 'You&#039;re connected', $html );
		$this->assertStringContainsString( 'Connected with Website URN <code>urn:supertab:website:manual</code>', $html );
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
			'Your site&#039;s address is now https://new.example.com, but it&#039;s connected to Supertab Connect as https://palmspringspost.com. Reconnect to update it.',
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

	/**
	 * Store a lookup record for the saved robots.txt, fresh for five minutes.
	 */
	private function record( ?string $body, bool $failed ): void {
		set_transient(
			'supertab_connect_robots_txt',
			array(
				'body'     => $body,
				'failed'   => $failed,
				'retry_at' => time() + 300,
			)
		);
	}

	/**
	 * Write a physical robots.txt in the site root; tearDown() removes it.
	 */
	private function write_physical_robots_txt(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->physical_robots_txt = get_home_path() . 'robots.txt';
		file_put_contents( $this->physical_robots_txt, "User-agent: *\n" );
	}

	/**
	 * Connect the site and turn robots.txt serving on, with the API unreachable
	 * so a lookup the test didn't arrange never leaves the machine.
	 */
	private function robots_txt_serving_on(): void {
		$this->connected_via_connect();
		$this->settings->set_robots_txt_serving_enabled( true );
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'blocked in tests' ) );
	}

	public function test_a_connected_site_can_turn_on_robots_txt_serving(): void {
		$this->connected_via_connect();

		$html = $this->render();

		$this->assertStringContainsString( 'id="supertab-serve-robots-txt"', $html );
		$this->assertStringContainsString( 'Serve robots.txt from Supertab Connect', $html );
		$this->assertStringContainsString( 'Edit robots.txt in Supertab Connect', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*id="supertab-serve-robots-txt"[^>]*checked/s', $html );
		$this->assertStringNotContainsString( 'supertab-saved-robots-txt-status', $html );
	}

	public function test_the_robots_txt_editor_link_opens_this_websites_editor(): void {
		$this->settings->save( 'the-key', 'urn:stc:merchant:system:0f8fad5b-d9cb-469f-a165-70867728950e' );

		$this->assertMatchesRegularExpression(
			'#<a href="' . preg_quote( SUPERTAB_CONNECT_DASHBOARD_URL, '#' ) . '/websites/0f8fad5b-d9cb-469f-a165-70867728950e/robots"[^>]*>\s*Edit robots.txt in Supertab Connect#',
			$this->render()
		);
	}

	public function test_a_new_site_has_no_robots_txt_section(): void {
		$this->assertStringNotContainsString( 'supertab-serve-robots-txt', $this->render() );
	}

	public function test_saving_turns_robots_txt_serving_on_and_off(): void {
		$this->connected_via_connect();

		$this->submit(
			array(
				'robots_txt_setting_present' => '1',
				'serve_robots_txt'           => '1',
			)
		);
		$this->assertTrue( $this->settings->is_robots_txt_serving_enabled() );

		$this->submit( array( 'robots_txt_setting_present' => '1' ) );
		$this->assertFalse( $this->settings->is_robots_txt_serving_enabled() );
	}

	public function test_a_form_without_the_checkbox_leaves_it_alone(): void {
		$this->settings->set_robots_txt_serving_enabled( true );

		$this->submit( array( 'merchant_api_key' => 'new-key' ) );

		$this->assertTrue( $this->settings->is_robots_txt_serving_enabled() );
	}

	public function test_purge_drops_license_xml_and_rechecks_robots_txt(): void {
		$this->connected_via_connect();
		set_transient( 'supertab_connect_license_xml', '<license/>' );
		$this->record( self::ROBOTS_FILE, false );

		$query = $this->submit( array( 'submit-purge-cache' => '1' ) );

		$this->assertFalse( get_transient( 'supertab_connect_license_xml' ) );
		$robots = get_transient( 'supertab_connect_robots_txt' );
		$this->assertSame( self::ROBOTS_FILE, $robots['body'], 'The copy stays, to serve if the API is down.' );
		$this->assertLessThanOrEqual( time(), $robots['retry_at'], 'The next request checks the API again.' );
		$this->assertSame( '1', $query['purged'] );
	}

	public function test_the_purge_notice_names_no_single_file(): void {
		$this->connected_via_connect();

		$this->assertStringContainsString( 'Cache purged successfully.', $this->render( array( 'purged' => '1' ) ) );
	}

	/**
	 * @dataProvider robots_txt_states
	 *
	 * @param callable(self): void $arrange Puts the site in the state.
	 * @param string               $status  The notice's data-status.
	 * @param string               $class   The notice class.
	 * @param string               $text    The message, unescaped.
	 */
	public function test_each_robots_txt_state_shows_its_message( callable $arrange, string $status, string $class, string $text ): void {
		$this->robots_txt_serving_on();
		$arrange( $this );

		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'#<div id="supertab-saved-robots-txt-status" class="notice inline ' . $class . '" data-status="' . $status . '">\s*<p>' . preg_quote( esc_html( $text ), '#' ) . '</p>#',
			$html
		);
	}

	/**
	 * @return array<string, array{0: callable(self): void, 1: string, 2: string, 3: string}>
	 */
	public static function robots_txt_states(): array {
		return array(
			'serving'           => array(
				static fn( self $test ) => $test->record( self::ROBOTS_FILE, false ),
				'serving',
				'notice-success',
				'Your website is serving the robots.txt saved in Supertab Connect.',
			),
			'not saved'         => array(
				static fn( self $test ) => $test->record( null, false ),
				'not_saved',
				'notice-info',
				'No robots.txt is saved in Supertab Connect yet, so WordPress is serving its own. Save one in the Supertab Connect Merchant Portal and your website starts serving it within a few minutes.',
			),
			'unreachable, copy' => array(
				static fn( self $test ) => $test->record( self::ROBOTS_FILE, true ),
				'unreachable_stale',
				'notice-warning',
				"Couldn't reach Supertab Connect. Your website is serving the last robots.txt it received.",
			),
			'unreachable'       => array(
				static fn( self $test ) => $test->record( null, true ),
				'unreachable',
				'notice-warning',
				"Couldn't reach Supertab Connect, so WordPress is serving its own robots.txt.",
			),
			'physical file'     => array(
				static function ( self $test ): void {
					$test->write_physical_robots_txt();
					$test->record( self::ROBOTS_FILE, false );
				},
				'physical_file',
				'notice-warning',
				'Your site has a robots.txt file stored on the server, and your website serves that file instead. Remove it to serve the robots.txt saved in Supertab Connect.',
			),
			'serving, search engines discouraged' => array(
				static function ( self $test ): void {
					update_option( 'blog_public', '0' );
					$test->record( self::ROBOTS_FILE, false );
				},
				'serving',
				'notice-success',
				'Your website is serving the robots.txt saved in Supertab Connect.',
			),
			'subdirectory'      => array(
				static function ( self $test ): void {
					$test->use_home( 'https://example.org/blog' );
					$test->write_physical_robots_txt();
				},
				'subdirectory',
				'notice-info',
				"WordPress is installed in a subdirectory, so it can't serve the robots.txt for your domain.",
			),
		);
	}
}
