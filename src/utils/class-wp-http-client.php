<?php
/**
 * WordPress HTTP client adapter for the Supertab Connect SDK.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Utils;

use Supertab\Connect\Exception\HttpException;
use Supertab\Connect\Http\HttpClientInterface;

/**
 * HTTP client that uses WordPress HTTP API with VIP-safe fallbacks.
 */
class WP_Http_Client implements HttpClientInterface {

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
		$args = array(
			'headers' => $headers,
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = vip_safe_wp_remote_get( $url, '', 3, 3, 20, $args );
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
		$args = array(
			'headers' => $headers,
			'body'    => $body,
		);

		$response = wp_remote_post( $url, $args );

		return $this->parse_response( $response );
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Error message is from WP_Error, used internally by SDK.
			throw new HttpException( $response->get_error_message(), 0 );
		}

		return array(
			'statusCode' => (int) wp_remote_retrieve_response_code( $response ),
			'body'       => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
