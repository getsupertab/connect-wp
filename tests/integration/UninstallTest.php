<?php
/**
 * Integration tests for uninstall.php.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

use Supertab_Connect\Settings;
use WP_UnitTestCase;

class UninstallTest extends WP_UnitTestCase {

	/**
	 * Requests sent while uninstalling.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The API's answer: a response array or a WP_Error.
	 *
	 * @var array<string, mixed>|\WP_Error
	 */
	private $api_response = array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => 204,
			'message' => '',
		),
		'cookies'  => array(),
	);

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'stc-1059/supertab-connect.php' );
		}
		add_filter(
			'pre_http_request',
			function ( $preempt, array $args, string $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->api_response;
			},
			10,
			3
		);
	}

	private function uninstall(): void {
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	private function connected_via( string $method ): void {
		$settings = new Settings();
		$settings->save( 'the-key', 'urn:supertab:website:42' );
		$settings->save_connection( $method, 'News', 'https://news.example.com' );
	}

	private function assert_options_gone(): void {
		$this->assertFalse( get_option( 'supertab_connect_merchant_api_key' ) );
		$this->assertFalse( get_option( 'supertab_connect_website_urn' ) );
		$this->assertFalse( get_option( 'supertab_connect_connection' ) );
	}

	public function test_uninstall_revokes_a_connect_issued_key(): void {
		$this->connected_via( 'connect' );

		$this->uninstall();

		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/website/setup/disconnect', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer the-key', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 3, $this->requests[0]['args']['timeout'] );
		$this->assert_options_gone();
	}

	public function test_uninstall_finishes_when_supertab_is_unreachable(): void {
		$this->connected_via( 'connect' );
		$this->api_response = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->uninstall();

		$this->assert_options_gone();
	}

	public function test_uninstall_leaves_a_pasted_key_alone(): void {
		$this->connected_via( 'manual' );

		$this->uninstall();

		$this->assertSame( array(), $this->requests );
		$this->assert_options_gone();
	}
}
