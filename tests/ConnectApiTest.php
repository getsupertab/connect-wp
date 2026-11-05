<?php
/**
 * Tests for the connect API client.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Connect_Api;
use Supertab_Connect\Connect_Api_Error;

class ConnectApiTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	private function respond( int $status, $body ): void {
		global $wp_test_http_response;
		$wp_test_http_response = array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : (string) json_encode( $body ),
		);
	}

	private function redeem(): array {
		return ( new Connect_Api( 'https://api.test' ) )->redeem( 'c0de', 'v3rifier', 'News', 'https://news.example.com/blog' );
	}

	private function redeem_reason(): string {
		try {
			$this->redeem();
		} catch ( Connect_Api_Error $error ) {
			return $error->reason;
		}
		$this->fail( 'redeem() did not throw' );
	}

	public function test_redeem_posts_the_exchange_and_returns_the_credentials(): void {
		global $wp_test_http_calls;
		$this->respond(
			200,
			array(
				'website_urn'  => 'urn:supertab:website:1',
				'website_name' => 'News',
				'base_url'     => 'https://news.example.com',
				'api_key'      => 'k3y',
				'reconnected'  => false,
			)
		);

		$this->assertSame(
			array(
				'website_urn'  => 'urn:supertab:website:1',
				'website_name' => 'News',
				'base_url'     => 'https://news.example.com',
				'api_key'      => 'k3y',
			),
			$this->redeem()
		);

		$this->assertCount( 1, $wp_test_http_calls );
		$call = $wp_test_http_calls[0];
		$this->assertSame( 'https://api.test/website/setup/credentials', $call['url'] );
		$this->assertSame( 'application/json', $call['args']['headers']['Content-Type'] );
		$this->assertSame( 15, $call['args']['timeout'] );
		$this->assertSame(
			array(
				'code'          => 'c0de',
				'code_verifier' => 'v3rifier',
				'name'          => 'News',
				'base_url'      => 'https://news.example.com/blog',
			),
			json_decode( $call['args']['body'], true )
		);
	}

	/**
	 * @dataProvider redeem_failures
	 */
	public function test_redeem_maps_failures_to_a_reason( int $status, $body, string $reason ): void {
		$this->respond( $status, $body );

		$this->assertSame( $reason, $this->redeem_reason() );
	}

	public static function redeem_failures(): array {
		return array(
			'expired or used code'  => array( 400, array( 'detail' => array( 'code' => 'invalid_code' ) ), 'invalid_code' ),
			'different site'        => array( 400, array( 'detail' => array( 'code' => 'site_mismatch' ) ), 'site_mismatch' ),
			'bad request'           => array( 400, array( 'detail' => array( 'code' => 'invalid_request' ) ), 'failed' ),
			'validation error'      => array( 422, array( 'detail' => array( array( 'msg' => 'x' ) ) ), 'failed' ),
			'success without a key' => array( 200, array( 'website_urn' => 'urn:x' ), 'failed' ),
			'not json'              => array( 200, '<html>', 'failed' ),
			'server down'           => array( 503, 'upstream', 'unreachable' ),
		);
	}

	public function test_redeem_reports_a_network_error_as_unreachable(): void {
		global $wp_test_http_response;
		$wp_test_http_response = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertSame( 'unreachable', $this->redeem_reason() );
	}

	public function test_disconnect_revokes_the_key(): void {
		global $wp_test_http_calls;
		$this->respond( 204, '' );

		$this->assertTrue( ( new Connect_Api( 'https://api.test/' ) )->disconnect( 'k3y' ) );

		$call = $wp_test_http_calls[0];
		$this->assertSame( 'https://api.test/website/setup/disconnect', $call['url'] );
		$this->assertSame( 'Bearer k3y', $call['args']['headers']['Authorization'] );
		$this->assertSame( 5, $call['args']['timeout'] );
	}

	public function test_disconnect_uses_the_given_timeout(): void {
		global $wp_test_http_calls;
		$this->respond( 204, '' );

		( new Connect_Api( 'https://api.test' ) )->disconnect( 'k3y', 3 );

		$this->assertSame( 3, $wp_test_http_calls[0]['args']['timeout'] );
	}

	public function test_disconnect_counts_an_already_revoked_key_as_done(): void {
		$this->respond( 401, array( 'detail' => 'Invalid API key' ) );

		$this->assertTrue( ( new Connect_Api( 'https://api.test' ) )->disconnect( 'k3y' ) );
	}

	public function test_disconnect_fails_when_supertab_errors(): void {
		$this->respond( 500, 'boom' );

		$this->assertFalse( ( new Connect_Api( 'https://api.test' ) )->disconnect( 'k3y' ) );
	}

	public function test_disconnect_fails_on_a_network_error(): void {
		global $wp_test_http_response;
		$wp_test_http_response = new \WP_Error( 'http_request_failed', 'cURL error 28' );

		$this->assertFalse( ( new Connect_Api( 'https://api.test' ) )->disconnect( 'k3y' ) );
	}
}
