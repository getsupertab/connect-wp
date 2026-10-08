<?php
/**
 * Tests for the Robots_Txt_Handler class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Robots_Txt_Handler;
use Supertab_Connect\Settings;

class RobotsTxtHandlerTest extends TestCase {

	/**
	 * What WordPress core generates for a public site with sitemaps enabled.
	 */
	private const WP_DEFAULT_OUTPUT = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.com/wp-sitemap.xml\n";

	private Settings $settings;

	private string $home_dir = '';

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();

		$this->settings = new Settings();
	}

	protected function tearDown(): void {
		if ( '' !== $this->home_dir ) {
			@unlink( $this->home_dir . 'robots.txt' );
			@rmdir( $this->home_dir );
		}

		wp_stubs_reset();
		parent::tearDown();
	}

	private function configure_urn(): void {
		$this->settings->save_website_urn( 'urn:supertab:website:example' );
	}

	/**
	 * Point get_home_path() at a fresh directory, optionally with a physical robots.txt.
	 */
	private function use_home_dir( ?string $robots_txt = null ): void {
		global $wp_test_home_path;

		$this->home_dir = sys_get_temp_dir() . '/supertab-robots-' . uniqid() . '/';
		mkdir( $this->home_dir );
		$wp_test_home_path = $this->home_dir;

		if ( null !== $robots_txt ) {
			file_put_contents( $this->home_dir . 'robots.txt', $robots_txt );
		}
	}

	public function test_register_filters_robots_txt_last(): void {
		global $wp_test_filters;

		$handler = new Robots_Txt_Handler( $this->settings );
		$handler->register();

		$this->assertCount( 1, $wp_test_filters );
		$this->assertSame( 'robots_txt', $wp_test_filters[0]['hook'] );
		$this->assertSame( array( $handler, 'add_license_directive' ), $wp_test_filters[0]['callback'] );
		$this->assertSame( PHP_INT_MAX, $wp_test_filters[0]['priority'], 'Must run after SEO plugins so it sees the License lines they add.' );
	}

	public function test_appends_license_directive_after_existing_output(): void {
		$this->configure_urn();

		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( self::WP_DEFAULT_OUTPUT );

		$this->assertSame(
			"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.com/wp-sitemap.xml\n\nLicense: https://example.com/license.xml\n",
			$output
		);
	}

	public function test_separates_directive_from_output_without_trailing_newline(): void {
		$this->configure_urn();

		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( "User-agent: *\nDisallow:" );

		$this->assertSame( "User-agent: *\nDisallow:\n\nLicense: https://example.com/license.xml\n", $output );
	}

	public function test_adds_directive_to_empty_output(): void {
		$this->configure_urn();

		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( '' );

		$this->assertSame( "License: https://example.com/license.xml\n", $output );
	}

	public function test_leaves_output_unchanged_without_website_urn(): void {
		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( self::WP_DEFAULT_OUTPUT );

		$this->assertSame( self::WP_DEFAULT_OUTPUT, $output );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function existing_license_directive_provider(): array {
		return array(
			'own license URL'        => array( "User-agent: *\nDisallow:\n\nLicense: https://example.com/license.xml\n" ),
			'license elsewhere'      => array( "License: https://cdn.example.net/terms/license.xml\nUser-agent: *\nDisallow:\n" ),
			'lowercase, no space'    => array( "User-agent: *\nDisallow:\nlicense:https://example.com/license.xml\n" ),
			'indented, space before' => array( "User-agent: *\nDisallow:\n  LICENSE : https://example.com/license.xml\n" ),
			'CRLF line endings'      => array( "User-agent: *\r\nDisallow:\r\nLicense: https://example.com/license.xml\r\n" ),
			'byte order mark'        => array( "\xEF\xBB\xBFLicense: https://example.com/license.xml\nUser-agent: *\n" ),
		);
	}

	/**
	 * @dataProvider existing_license_directive_provider
	 */
	public function test_keeps_existing_license_directive_untouched( string $existing ): void {
		$this->configure_urn();

		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( $existing );

		$this->assertSame( $existing, $output );
	}

	public function test_commented_out_license_line_does_not_count_as_directive(): void {
		$this->configure_urn();

		$output = ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( "# License: https://example.com/license.xml\nUser-agent: *\nDisallow:\n" );

		$this->assertSame( "# License: https://example.com/license.xml\nUser-agent: *\nDisallow:\n\nLicense: https://example.com/license.xml\n", $output );
	}

	public function test_passes_through_non_string_output_from_earlier_filters(): void {
		$this->configure_urn();

		$this->assertNull( ( new Robots_Txt_Handler( $this->settings ) )->add_license_directive( null ) );
	}

	public function test_physical_file_without_directive_needs_manual_directive(): void {
		$this->configure_urn();
		$this->use_home_dir( "User-agent: *\nDisallow: /private/\n" );

		$this->assertTrue( ( new Robots_Txt_Handler( $this->settings ) )->needs_manual_license_directive() );
	}

	public function test_physical_file_with_directive_needs_nothing(): void {
		$this->configure_urn();
		$this->use_home_dir( "User-agent: *\nDisallow: /private/\n\nLicense: https://example.com/license.xml\n" );

		$this->assertFalse( ( new Robots_Txt_Handler( $this->settings ) )->needs_manual_license_directive() );
	}

	public function test_unreadable_physical_file_needs_manual_directive(): void {
		$this->configure_urn();
		$this->use_home_dir( "License: https://example.com/license.xml\n" );
		chmod( $this->home_dir . 'robots.txt', 0000 );

		if ( is_readable( $this->home_dir . 'robots.txt' ) ) {
			$this->markTestSkipped( 'File permissions are not enforced for this user.' );
		}

		$this->assertTrue( ( new Robots_Txt_Handler( $this->settings ) )->needs_manual_license_directive() );
	}

	public function test_generated_robots_txt_needs_nothing(): void {
		$this->configure_urn();
		$this->use_home_dir();

		$this->assertFalse( ( new Robots_Txt_Handler( $this->settings ) )->needs_manual_license_directive() );
	}

	public function test_physical_file_needs_nothing_without_website_urn(): void {
		$this->use_home_dir( "User-agent: *\nDisallow: /private/\n" );

		$this->assertFalse( ( new Robots_Txt_Handler( $this->settings ) )->needs_manual_license_directive() );
	}
}
