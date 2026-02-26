<?php
/**
 * RSL License handler.
 *
 * Intercepts requests for /license.xml and proxies the response
 * from the Supertab Connect API.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

/**
 * Serves the license.xml file by proxying from the Supertab API.
 */
class RSL_License_Handler {

	/**
	 * The request path this handler intercepts.
	 *
	 * @var string
	 */
	private const REQUEST_LICENSE_PATH = 'license.xml';

	/**
	 * Credentials instance.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Base URL for the Supertab Connect API.
	 *
	 * @var string
	 */
	private string $api_base_url;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials  Credentials manager.
	 * @param string      $api_base_url Base URL for the Supertab Connect API.
	 */
	public function __construct( Credentials $credentials, string $api_base_url ) {
		$this->credentials  = $credentials;
		$this->api_base_url = $api_base_url;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve_license' ) );
	}

	/**
	 * Serve license.xml if the current request matches.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function maybe_serve_license( \WP $wp ): void {
		if ( self::REQUEST_LICENSE_PATH !== $wp->request ) {
			return;
		}

		if ( ! $this->credentials->has_credentials() ) {
			return;
		}

		$response = $this->fetch_license();

		if ( is_wp_error( $response ) ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$this->send_error( (int) $status_code, wp_remote_retrieve_response_message( $response ) );
			return;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === trim( (string) $body ) ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		if ( false === simplexml_load_string( (string) $body, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		$this->send_xml( $body );
	}

	/**
	 * Fetch the license XML from the Supertab API.
	 *
	 * @return array<string, mixed>|\WP_Error The raw response or WP_Error on failure.
	 */
	private function fetch_license() {
		$url = sprintf(
			'%s/merchants/systems/%s/license.xml',
			$this->api_base_url,
			rawurlencode( $this->credentials->get_website_urn() )
		);

		$args = array(
			'headers' => array(
				'Accept' => 'application/xml',
			),
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, 3, 20, $args );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback for non-VIP environments.
		return wp_remote_get( $url, $args );
	}

	/**
	 * Send an XML response body and terminate.
	 *
	 * @param string $body The XML body to send.
	 * @return void
	 */
	private function send_xml( string $body ): void {
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proxied XML from trusted API, must be served verbatim.
		echo $body;
		exit;
	}

	/**
	 * Send an error response and terminate.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $message     Brief error description.
	 * @return void
	 */
	private function send_error( int $status_code, string $message ): void {
		status_header( $status_code );
		header( 'Content-Type: application/xml; charset=UTF-8' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<error>' . esc_html( $message ) . '</error>';
		exit;
	}
}
