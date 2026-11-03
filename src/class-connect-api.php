<?php
/**
 * Client for the Supertab Connect connect endpoints.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

use Supertab\Connect\Exception\HttpException;
use Supertab_Connect\Utils\WP_Http_Client;

/**
 * Redeems a portal code for credentials and revokes plugin-issued keys.
 */
final class Connect_Api {

	public const REDEEM_TIMEOUT     = 15;
	public const DISCONNECT_TIMEOUT = 5;

	/**
	 * Connect API base URL without a trailing slash.
	 *
	 * @var string
	 */
	private string $api_base_url;

	/**
	 * Constructor.
	 *
	 * @param string $api_base_url Connect API base URL.
	 */
	public function __construct( string $api_base_url ) {
		$this->api_base_url = rtrim( $api_base_url, '/' );
	}

	/**
	 * Exchange a one-time code and its PKCE verifier for the website's URN and a new API key.
	 *
	 * @param string $code          One-time code from the portal.
	 * @param string $code_verifier PKCE verifier the code's challenge came from.
	 * @param string $name          Website name, used when the website is created.
	 * @param string $base_url      The site's home URL, path included.
	 * @return array{website_urn: string, website_name: string, base_url: string, api_key: string}
	 *
	 * @throws Connect_Api_Error When the API rejects the code or can't be reached.
	 */
	public function redeem( string $code, string $code_verifier, string $name, string $base_url ): array {
		$body = (string) wp_json_encode(
			array(
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'name'          => $name,
				'base_url'      => $base_url,
			)
		);

		try {
			$response = ( new WP_Http_Client( self::REDEEM_TIMEOUT ) )->post(
				$this->api_base_url . '/connect/websites',
				$body,
				array( 'Content-Type' => 'application/json' )
			);
		} catch ( HttpException $e ) {
			throw new Connect_Api_Error( 'unreachable' );
		}

		$status = $response['statusCode'];
		$data   = json_decode( $response['body'], true );

		if ( 200 === $status ) {
			return self::credentials( $data );
		}
		if ( $status >= 500 ) {
			throw new Connect_Api_Error( 'unreachable' );
		}

		$reason = is_array( $data ) && is_array( $data['detail'] ?? null ) ? ( $data['detail']['code'] ?? '' ) : '';
		if ( 'invalid_code' === $reason ) {
			throw new Connect_Api_Error( 'invalid_code' );
		}
		if ( 'site_mismatch' === $reason ) {
			throw new Connect_Api_Error( 'site_mismatch' );
		}
		// invalid_request, a 422 and anything unexpected.
		throw new Connect_Api_Error( 'failed' );
	}

	/**
	 * Revoke the API key that makes the call.
	 *
	 * @param string $api_key Plugin-issued API key.
	 * @param int    $timeout Request timeout in seconds.
	 * @return bool True when the key is revoked or was already invalid.
	 */
	public function disconnect( string $api_key, int $timeout = self::DISCONNECT_TIMEOUT ): bool {
		try {
			$response = ( new WP_Http_Client( $timeout ) )->post(
				$this->api_base_url . '/connect/disconnect',
				'',
				array( 'Authorization' => 'Bearer ' . $api_key )
			);
		} catch ( HttpException $e ) {
			return false;
		}

		// 401: the key was already revoked, e.g. in the portal.
		return in_array( $response['statusCode'], array( 204, 401 ), true );
	}

	/**
	 * The credentials from a successful redeem, or a failure when any is missing.
	 *
	 * @param mixed $data Decoded response body.
	 * @return array{website_urn: string, website_name: string, base_url: string, api_key: string}
	 *
	 * @throws Connect_Api_Error When a field is missing.
	 */
	private static function credentials( $data ): array {
		$credentials = array();
		foreach ( array( 'website_urn', 'website_name', 'base_url', 'api_key' ) as $field ) {
			if ( ! is_array( $data ) || ! is_string( $data[ $field ] ?? null ) || '' === $data[ $field ] ) {
				throw new Connect_Api_Error( 'failed' );
			}
			$credentials[ $field ] = $data[ $field ];
		}

		return $credentials;
	}
}
