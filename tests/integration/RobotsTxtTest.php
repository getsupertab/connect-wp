<?php
/**
 * Integration tests for the robots.txt License directive and the settings
 * page warning shown when a physical robots.txt file hides the generated one.
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Admin\Settings_Page;
use Supertab_Connect\Robots_Txt_Handler;
use Supertab_Connect\Settings;
use WP_UnitTestCase;

class RobotsTxtTest extends WP_UnitTestCase {

	private string $physical_robots_txt = '';

	protected function setUp(): void {
		parent::setUp();

		delete_option( 'supertab_connect_website_urn' );
		delete_option( 'supertab_connect_merchant_api_key' );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->physical_robots_txt = get_home_path() . 'robots.txt';
	}

	protected function tearDown(): void {
		if ( file_exists( $this->physical_robots_txt ) ) {
			unlink( $this->physical_robots_txt );
		}

		parent::tearDown();
	}

	/**
	 * Proves the plugin registers the filter at boot and that it reaches the
	 * output WordPress core builds, Sitemap line included.
	 */
	public function test_generated_robots_txt_ends_with_license_directive(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );

		$output = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' );

		$this->assertStringEndsWith( "\n\nLicense: " . home_url( '/license.xml' ) . "\n", $output );
	}

	public function test_generated_robots_txt_is_unchanged_without_website_urn(): void {
		$output = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' );

		$this->assertStringNotContainsStringIgnoringCase( 'license:', $output );
	}

	/**
	 * SEO plugins such as Yoast filter robots.txt at a late priority; a License
	 * line they add must win over ours rather than be duplicated.
	 */
	public function test_license_directive_added_by_late_filter_is_not_duplicated(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		add_filter(
			'robots_txt',
			static fn ( string $output ): string => $output . "License: https://cdn.example.net/license.xml\n",
			99999
		);

		$output = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", '1' );

		$this->assertSame( 1, substr_count( strtolower( $output ), 'license:' ) );
		$this->assertStringContainsString( 'License: https://cdn.example.net/license.xml', $output );
	}

	public function test_settings_page_warns_when_physical_robots_txt_lacks_directive(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		file_put_contents( $this->physical_robots_txt, "User-agent: *\nDisallow: /private/\n" );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'id="supertab-robots-txt-notice"', $html );
		$this->assertStringContainsString( 'License: ' . home_url( '/license.xml' ), $html );
		$this->assertStringNotContainsString( 'supertab-robots-txt-other-license-notice', $html );
	}

	public function test_settings_page_notes_physical_robots_txt_pointing_to_other_license(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		file_put_contents( $this->physical_robots_txt, "User-agent: *\nDisallow:\n\nLicense: https://cdn.example.net/license.xml\n" );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( 'id="supertab-robots-txt-other-license-notice"', $html );
		$this->assertStringContainsString( 'License: https://cdn.example.net/license.xml', $html );
		$this->assertStringContainsString( 'License: ' . home_url( '/license.xml' ), $html );
		$this->assertStringNotContainsString( 'id="supertab-robots-txt-notice"', $html );
	}

	public function test_settings_page_has_no_robots_notice_when_physical_file_declares_own_license(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );
		file_put_contents( $this->physical_robots_txt, "User-agent: *\nDisallow:\n\nLicense: " . home_url( '/license.xml' ) . "\n" );

		$html = $this->render_settings_page();

		$this->assertStringNotContainsString( 'supertab-robots-txt-notice', $html );
		$this->assertStringNotContainsString( 'supertab-robots-txt-other-license-notice', $html );
	}

	public function test_settings_page_has_no_robots_warning_without_physical_file(): void {
		update_option( 'supertab_connect_website_urn', 'urn:supertab:website:example' );

		$html = $this->render_settings_page();

		$this->assertStringNotContainsString( 'supertab-robots-txt-notice', $html );
	}

	private function render_settings_page(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings();
		$page     = new Settings_Page( $settings, new Robots_Txt_Handler( $settings ) );

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}
}
