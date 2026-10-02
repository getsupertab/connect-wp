<?php
/**
 * WordPress HTTP client adapter for the Supertab Connect SDK.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Utils;

use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\HttpClientInterface;

/**
 * HTTP client that uses WordPress HTTP API with VIP-safe fallbacks.
 */
class WP_Http_Client implements HttpClientInterface {

	/**
	 * Request timeout in seconds; null keeps the WordPress default.
	 *
	 * @var ?int
	 */
	private ?int $timeout;

	/**
	 * Constructor.
	 *
	 * @param ?int $timeout Request timeout in seconds; null keeps the WordPress default.
	 */
	public function __construct( ?int $timeout = null ) {
		$this->timeout = $timeout;
	}

	/**
	 * Perform a GET request.
	 *
	 * @param string               $url     Request URL.
	 * @param array<string,string> $headers Request headers.
	 * @return array{statusCode: int, body: string}
	 *
	 * @throws HttpException On request failure.
	 */
	public function get( string $url, array $headers = array() ): array {
		$args = $this->with_timeout(
			array(
				'headers'    => $headers,
				'user-agent' => HttpClient::resolveUserAgent(),
			)
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, '', 3, $this->timeout ?? 3, 20, $args );
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback for non-VIP environments.
			$response = wp_remote_get( $url, $args );
		}

		return $this->parse_response( $response );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string               $url     Request URL.
	 * @param string               $body    Request body.
	 * @param array<string,string> $headers Request headers.
	 * @return array{statusCode: int, body: string}
	 *
	 * @throws HttpException On request failure.
	 */
	public function post( string $url, string $body, array $headers = array() ): array {
		$args = $this->with_timeout(
			array(
				'headers'    => $headers,
				'body'       => $body,
				'user-agent' => HttpClient::resolveUserAgent(),
			)
		);

		$response = wp_remote_post( $url, $args );

		return $this->parse_response( $response );
	}

	/**
	 * Add the configured timeout to request args, if one is set.
	 *
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>
	 */
	private function with_timeout( array $args ): array {
		if ( null !== $this->timeout ) {
			$args['timeout'] = $this->timeout;
		}

		return $args;
	}

	/**
	 * Parse a WordPress HTTP response into the SDK format.
	 *
	 * @param array<string,mixed>|\WP_Error $response WordPress HTTP response.
	 * @return array{statusCode: int, body: string}
	 *
	 * @throws HttpException On WP_Error.
	 */
	private function parse_response( $response ): array {
		if ( is_wp_error( $response ) ) {
			throw new HttpException( esc_html( $response->get_error_message() ), 0 );
		}

		return array(
			'statusCode' => (int) wp_remote_retrieve_response_code( $response ),
			'body'       => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
