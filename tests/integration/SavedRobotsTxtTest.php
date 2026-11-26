<?php
/**
 * Integration tests for serving the robots.txt saved in Supertab Connect.
 *
 * Never go_to() /robots.txt in a state that serves the saved file: the
 * handler exits, which ends the test run.
 *
 * @package Supertab_Connect\Tests\Integration
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Saved_Robots_Txt_Handler;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\WP_Http_Client;
use WP_UnitTestCase;

class SavedRobotsTxtTest extends WP_UnitTestCase {

	private const URN = 'urn:stc:merchant:system:abc';

	private const FILE = "User-agent: *\nDisallow: /private/\n";

	/**
	 * Requests sent to the API.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	protected function setUp(): void {
		parent::setUp();

		delete_option( 'supertab_connect_website_urn' );
		delete_option( 'supertab_connect_serve_robots_txt' );
		delete_transient( 'supertab_connect_robots_txt' );

		// See LicenseRoutingTest: pretty permalinks are needed for $wp->request.
		$this->set_permalink_structure( '/%postname%/' );

		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array(),
					'body'     => self::FILE,
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	private function use_home( string $home ): void {
		remove_all_filters( 'pre_option_home' );
		remove_all_filters( 'pre_option_siteurl' );
		add_filter( 'pre_option_home', static fn() => $home );
		add_filter( 'pre_option_siteurl', static fn() => $home );
	}

	private function on(): void {
		$settings = new Settings();
		$settings->save_website_urn( self::URN );
		$settings->set_robots_txt_serving_enabled( true );
	}

	private function handler(): Saved_Robots_Txt_Handler {
		return new Saved_Robots_Txt_Handler( new Settings(), 'https://api.test', new WP_Http_Client( 3 ) );
	}

	public function test_robots_txt_reaches_parse_request(): void {
		$this->go_to( home_url( '/robots.txt' ) );

		$this->assertSame( 'robots.txt', $GLOBALS['wp']->request );
	}

	public function test_a_query_string_is_still_robots_txt(): void {
		$this->go_to( home_url( '/robots.txt?x=1' ) );

		$this->assertSame( 'robots.txt', $GLOBALS['wp']->request );
	}

	public function test_fetches_through_the_wordpress_http_api(): void {
		$this->on();

		$this->assertSame( self::FILE, $this->handler()->body_to_serve() );
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/merchants/systems/' . self::URN . '/robots.txt', $this->requests[0]['url'] );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
	}

	public function test_a_subdirectory_install_is_not_served(): void {
		$this->on();
		$this->use_home( 'https://example.org/blog' );
		$handler = $this->handler();

		$this->assertSame( 'subdirectory', $handler->blocked_by() );
		$this->assertNull( $handler->body_to_serve() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_trailing_slash_home_is_not_a_subdirectory(): void {
		$this->on();
		$this->use_home( 'https://example.org/' );

		$this->assertNull( $this->handler()->blocked_by() );
	}

	public function test_a_discouraged_site_is_not_served(): void {
		$this->on();
		update_option( 'blog_public', '0' );

		$this->assertSame( 'discouraged', $this->handler()->blocked_by() );
	}

	public function test_with_the_checkbox_off_wordpress_adds_the_license_line(): void {
		( new Settings() )->save_website_urn( self::URN );

		// phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.robots_txt -- Reading the filter's output, not changing it.
		$output = apply_filters( 'robots_txt', "User-agent: *\n", '1' );

		$this->assertStringEndsWith( 'License: ' . home_url( '/license.xml' ) . "\n", $output );
	}
}
