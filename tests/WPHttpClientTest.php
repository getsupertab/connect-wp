<?php
/**
 * Tests for the WP_Http_Client adapter.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab\Connect\Http\HttpClient;
use Supertab_Connect\Utils\WP_Http_Client;
use Yoast\PHPUnitPolyfills\Polyfills\AssertionRenames;

class WPHttpClientTest extends TestCase {

	use AssertionRenames;

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();
	}

	protected function tearDown(): void {
		wp_stubs_reset();
		parent::tearDown();
	}

	public function test_post_sends_sdk_user_agent(): void {
		global $wp_test_http_calls;

		( new WP_Http_Client() )->post(
			'https://api-connect.supertab.co/ingest/events',
			'{}',
			[ 'Authorization' => 'Bearer key' ]
		);

		$args = $wp_test_http_calls[0]['args'];
		$this->assertArrayHasKey( 'user-agent', $args );
		$this->assertSame( HttpClient::resolveUserAgent(), $args['user-agent'] );
		$this->assertStringStartsWith( 'supertab-connect-sdk-php/', $args['user-agent'] );
	}

	public function test_get_sends_sdk_user_agent(): void {
		global $wp_test_http_calls;

		( new WP_Http_Client() )->get( 'https://api-connect.supertab.co/.well-known/jwks.json' );

		$args = $wp_test_http_calls[0]['args'];
		$this->assertArrayHasKey( 'user-agent', $args );
		$this->assertSame( HttpClient::resolveUserAgent(), $args['user-agent'] );
	}

	public function test_post_preserves_caller_headers_and_body(): void {
		global $wp_test_http_calls;

		( new WP_Http_Client() )->post(
			'https://api-connect.supertab.co/ingest/events',
			'{"a":1}',
			[ 'Authorization' => 'Bearer key', 'Content-Type' => 'application/json' ]
		);

		$call = $wp_test_http_calls[0];
		$this->assertSame( '{"a":1}', $call['args']['body'] );
		$this->assertSame( 'Bearer key', $call['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $call['args']['headers']['Content-Type'] );
	}
}
