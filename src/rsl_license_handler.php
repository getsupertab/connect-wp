<?php
/**
 * RSL License handler.
 *
 * Intercepts requests for /license.xml and proxies the response
 * from the Supertab Connect API via the Connect PHP SDK.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

use Supertab\Connect\Exception\SupertabConnectException;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\SupertabConnect;

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
	 * HTTP client for SDK requests.
	 *
	 * @var HttpClientInterface
	 */
	private HttpClientInterface $http_client;

	/**
	 * Constructor.
	 *
	 * @param Credentials         $credentials  Credentials manager.
	 * @param string              $api_base_url Base URL for the Supertab Connect API.
	 * @param HttpClientInterface $http_client  HTTP client for SDK requests.
	 */
	public function __construct( Credentials $credentials, string $api_base_url, HttpClientInterface $http_client ) {
		$this->credentials  = $credentials;
		$this->api_base_url = $api_base_url;
		$this->http_client  = $http_client;
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

		try {
			$body = SupertabConnect::fetchLicenseXml(
				$this->credentials->get_website_urn(),
				$this->api_base_url,
				$this->http_client
			);
		} catch ( SupertabConnectException $e ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		if ( '' === trim( $body ) ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		if ( false === simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		$this->send_xml( $body );
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
