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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Transient key for cached license XML.
	 *
	 * @var string
	 */
	public const CACHE_TRANSIENT_KEY = 'supertab_connect_license_xml';

	/**
	 * Cache TTL in seconds (0 = no expiration).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 0;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * @param Settings            $settings     Settings manager.
	 * @param string              $api_base_url Base URL for the Supertab Connect API.
	 * @param HttpClientInterface $http_client  HTTP client for SDK requests.
	 */
	public function __construct( Settings $settings, string $api_base_url, HttpClientInterface $http_client ) {
		$this->settings     = $settings;
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

		if ( ! $this->settings->has_website_urn() ) {
			return;
		}

		$cached = get_transient( self::CACHE_TRANSIENT_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			$this->send_xml( $cached );
			return;
		}

		$body = $this->fetch_license_xml();
		if ( null === $body ) {
			$this->send_error( 502, 'Bad Gateway' );
			return;
		}

		set_transient( self::CACHE_TRANSIENT_KEY, $body, self::CACHE_TTL );
		$this->send_xml( $body );
	}

	/**
	 * Fetch and validate license XML from the upstream API.
	 *
	 * @return string|null The XML body, or null on failure.
	 */
	private function fetch_license_xml(): ?string {
		try {
			$body = SupertabConnect::fetchLicenseXml(
				$this->settings->get_website_urn(),
				$this->api_base_url,
				$this->http_client
			);
		} catch ( SupertabConnectException $e ) {
			return null;
		}

		if ( '' === trim( $body ) ) {
			return null;
		}

		if ( false === simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			return null;
		}

		return $body;
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

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Proxied XML from trusted API, must be served verbatim. Validated using simplexml_load_string() in fetch_license_xml().
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
