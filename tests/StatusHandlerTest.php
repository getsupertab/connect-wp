<?php
/**
 * Tests for the Status_Handler class.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests;

use PHPUnit\Framework\TestCase;
use Supertab_Connect\Settings;
use Supertab_Connect\Status_Handler;
use Supertab\Connect\Http\HttpClientInterface;

class StatusHandlerTest extends TestCase {

	private Settings $settings;

	private HttpClientInterface $http_client;

	/**
	 * Snapshot of the $_SERVER authorization keys, restored in tearDown so
	 * tests that mutate them cannot leak state (PHPUnit does not back up
	 * superglobals in this configuration).
	 *
	 * @var array<string, string|null>
	 */
	private array $server_auth_snapshot = array();

	protected function setUp(): void {
		parent::setUp();
		wp_stubs_reset();

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			$this->server_auth_snapshot[ $key ] = $_SERVER[ $key ] ?? null;
		}

		$this->settings    = new Settings();
		$this->http_client = $this->createMock( HttpClientInterface::class );
	}

	protected function tearDown(): void {
		foreach ( $this->server_auth_snapshot as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}

		wp_stubs_reset();
		parent::tearDown();
	}

	private function create_handler( ?\Closure $verify_challenge = null ): Status_Handler {
		return new Status_Handler(
			$this->settings,
			'https://api-connect.sbx.supertab.co',
			$this->http_client,
			$verify_challenge
		);
	}

	private function enable_bot_protection(): void {
		$this->settings->save( 'test-key', 'urn:supertab:website:test' );
		$this->settings->set_bot_protection_enabled( true );
	}

	public function test_missing_authorization_returns_decoy(): void {
		$handler  = $this->create_handler( static fn (): bool => true );
		$response = $handler->build_response( '', 'https://example.com' );

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( array( 'supertab' => true ), json_decode( $response['body'], true ) );
	}

	public function test_non_bearer_authorization_returns_decoy_without_verifying(): void {
		$called  = false;
		$handler = $this->create_handler(
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);

		$response = $handler->build_response( 'License some-token', 'https://example.com' );

		$this->assertSame( 404, $response['status'] );
		$this->assertFalse( $called, 'Verifier must not run without a Bearer token.' );
	}

	public function test_invalid_challenge_returns_decoy(): void {
		$handler  = $this->create_handler( static fn (): bool => false );
		$response = $handler->build_response( 'Bearer bad-token', 'https://example.com' );

		$this->assertSame( 404, $response['status'] );
		$this->assertSame( array( 'supertab' => true ), json_decode( $response['body'], true ) );
	}

	public function test_throwing_verifier_returns_decoy(): void {
		$handler = $this->create_handler(
			static function (): bool {
				throw new \RuntimeException( 'JWKS fetch failed' );
			}
		);

		$response = $handler->build_response( 'Bearer token', 'https://example.com' );

		$this->assertSame( 404, $response['status'] );
	}

	public function test_empty_audience_returns_decoy_without_verifying(): void {
		$called  = false;
		$handler = $this->create_handler(
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);

		$response = $handler->build_response( 'Bearer token', '' );

		$this->assertSame( 404, $response['status'] );
		$this->assertFalse( $called, 'Verifier must not run without an audience.' );
	}

	public function test_verifier_receives_token_and_audience(): void {
		$seen    = array();
		$handler = $this->create_handler(
			static function ( string $token, string $audience ) use ( &$seen ): bool {
				$seen = array( $token, $audience );
				return false;
			}
		);

		$handler->build_response( 'Bearer the-token', 'https://example.com' );

		$this->assertSame( array( 'the-token', 'https://example.com' ), $seen );
	}

	public function test_valid_challenge_returns_status_payload(): void {
		$this->enable_bot_protection();

		$handler  = $this->create_handler( static fn (): bool => true );
		$response = $handler->build_response( 'Bearer good-token', 'https://example.com' );

		$this->assertSame( 200, $response['status'] );

		$payload = json_decode( $response['body'], true );

		$this->assertNull( $payload['runtime'] );
		$this->assertIsString( $payload['sdkVersion'] );
		$this->assertNotSame( '', $payload['sdkVersion'] );
		$this->assertSame(
			array(
				'kind'    => 'wordpress-plugin',
				'version' => SUPERTAB_CONNECT_VERSION,
			),
			$payload['component']
		);
		$this->assertSame( 'observe', $payload['enforcement'] );
		$this->assertTrue( $payload['eventReporting'] );
	}

	public function test_disabled_bot_protection_reports_disabled(): void {
		$this->settings->save( 'test-key', 'urn:supertab:website:test' );
		// Bot protection flag left off.

		$handler  = $this->create_handler( static fn (): bool => true );
		$response = $handler->build_response( 'Bearer good-token', 'https://example.com' );

		$payload = json_decode( $response['body'], true );

		$this->assertSame( 200, $response['status'] );
		$this->assertSame( 'disabled', $payload['enforcement'] );
		$this->assertFalse( $payload['eventReporting'] );
	}

	public function test_missing_credentials_reports_disabled(): void {
		$this->settings->set_bot_protection_enabled( true );
		// No merchant API key saved.

		$handler  = $this->create_handler( static fn (): bool => true );
		$response = $handler->build_response( 'Bearer good-token', 'https://example.com' );

		$payload = json_decode( $response['body'], true );

		$this->assertSame( 'disabled', $payload['enforcement'] );
		$this->assertFalse( $payload['eventReporting'] );
	}

	public function test_ignores_non_status_requests(): void {
		$called  = false;
		$handler = $this->create_handler(
			static function () use ( &$called ): bool {
				$called = true;
				return true;
			}
		);

		$wp          = new \WP();
		$wp->request = 'some-other-page';

		// Returns without responding; an exit here would kill the whole suite.
		$handler->maybe_handle_request( $wp );

		$this->assertFalse( $called, 'Verifier must not run for other paths.' );
	}

	public function test_authorization_header_falls_back_to_raw_request_headers(): void {
		// Apache withholds Authorization from the CGI-style $_SERVER variables;
		// getallheaders() still exposes it. Regression test for the live bug
		// where every backend challenge probe received the 404 decoy.
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$handler = $this->create_handler_with_raw_headers( array( 'authorization' => 'Bearer apache-token' ) );

		$this->assertSame( 'Bearer apache-token', $handler->exposed_authorization_header() );
	}

	public function test_authorization_header_prefers_server_over_raw_request_headers(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer from-server';

		$handler = $this->create_handler_with_raw_headers( array( 'Authorization' => 'Bearer from-raw-headers' ) );

		$this->assertSame( 'Bearer from-server', $handler->exposed_authorization_header() );
	}

	public function test_authorization_header_empty_when_absent_everywhere(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$handler = $this->create_handler_with_raw_headers( array( 'Accept' => 'application/json' ) );

		$this->assertSame( '', $handler->exposed_authorization_header() );
	}

	/**
	 * Build a Status_Handler whose raw request headers are injected, with the
	 * protected header resolution exposed for assertions.
	 *
	 * @param array<string, string> $raw_headers Raw request headers to inject.
	 */
	private function create_handler_with_raw_headers( array $raw_headers ) {
		return new class( $this->settings, 'https://api-connect.sbx.supertab.co', $this->http_client, null, $raw_headers ) extends Status_Handler {
			/**
			 * Raw request headers injected by the test.
			 *
			 * @var array<string, string>
			 */
			private array $raw_headers;

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function __construct( Settings $settings, string $api_base_url, HttpClientInterface $http_client, ?\Closure $verify_challenge, array $raw_headers ) {
				parent::__construct( $settings, $api_base_url, $http_client, $verify_challenge );
				$this->raw_headers = $raw_headers;
			}

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			public function exposed_authorization_header(): string {
				return $this->get_authorization_header();
			}

			// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
			protected function get_raw_request_headers(): array {
				return $this->raw_headers;
			}
		};
	}

	public function test_register_hooks_parse_request_before_bot_protection(): void {
		global $wp_test_actions;

		$handler = $this->create_handler();
		$handler->register();

		$this->assertCount( 1, $wp_test_actions );
		$this->assertSame( 'parse_request', $wp_test_actions[0]['hook'] );
		$this->assertSame( array( $handler, 'maybe_handle_request' ), $wp_test_actions[0]['callback'] );
		$this->assertSame( 5, $wp_test_actions[0]['priority'], 'Must run before Bot_Protection (priority 9).' );
	}
}
