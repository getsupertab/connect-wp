<?php
/**
 * Self-report status endpoint handler.
 *
 * Serves /.well-known/supertab/status so the Supertab Connect API can
 * probe the site and learn the running plugin/SDK versions and effective
 * bot-protection configuration. Requests must carry a backend-minted
 * challenge JWT; anything else receives a minimal 404 decoy.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab\Connect\Http\HttpClient;
use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Http\RequestContext;
use Supertab\Connect\Jwks\JwksProvider;
use Supertab\Connect\Status\StatusChallengeVerifier;
use Supertab_Connect\Utils\WP_Transient_Cache;

/**
 * Serves the self-report status endpoint for backend version probes.
 */
class Status_Handler {

	/**
	 * The request path this handler intercepts (as seen in \WP::$request).
	 *
	 * @var string
	 */
	private const REQUEST_STATUS_PATH = '.well-known/supertab/status';

	/**
	 * Component kind the backend maps to the wordpress.org update registry.
	 *
	 * @var string
	 */
	private const COMPONENT_KIND = 'wordpress-plugin';

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
	 * Challenge verification override, used by tests. Signature:
	 * fn( string $token, string $expected_audience ): bool.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $verify_challenge;

	/**
	 * Lazily built SDK verifier (production path).
	 *
	 * @var StatusChallengeVerifier|null
	 */
	private ?StatusChallengeVerifier $verifier = null;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings         Settings manager.
	 * @param string              $api_base_url     Base URL for the Supertab Connect API.
	 * @param HttpClientInterface $http_client      HTTP client for SDK requests.
	 * @param \Closure|null       $verify_challenge Optional challenge verification override (tests).
	 */
	public function __construct(
		Settings $settings,
		string $api_base_url,
		HttpClientInterface $http_client,
		?\Closure $verify_challenge = null
	) {
		$this->settings         = $settings;
		$this->api_base_url     = $api_base_url;
		$this->http_client      = $http_client;
		$this->verify_challenge = $verify_challenge;
	}

	/**
	 * Register hooks. Priority 5 runs before Bot_Protection (9) so a probe
	 * never reaches bot detection or analytics.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_handle_request' ), 5 );
	}

	/**
	 * Serve the status endpoint if the current request matches.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function maybe_handle_request( \WP $wp ): void {
		if ( self::REQUEST_STATUS_PATH !== $wp->request ) {
			return;
		}

		$response = $this->build_response( $this->get_authorization_header(), $this->get_request_origin() );

		$this->send_json( $response['status'], $response['body'] );
	}

	/**
	 * Build the status response for the given credentials.
	 *
	 * A request carrying a valid backend-minted challenge (ES256 JWT with
	 * purpose "status-probe", aud = the request origin) gets the live config;
	 * anything else gets a minimal 404 decoy.
	 *
	 * @param string $authorization_header Raw Authorization header value.
	 * @param string $expected_audience    Request origin (scheme://host[:port]).
	 * @return array{status: int, body: string}
	 */
	public function build_response( string $authorization_header, string $expected_audience ): array {
		$token = str_starts_with( $authorization_header, 'Bearer ' )
			? substr( $authorization_header, 7 )
			: '';

		$verified = '' !== $token
			&& '' !== $expected_audience
			&& $this->verify_challenge( $token, $expected_audience );

		if ( ! $verified ) {
			return array(
				'status' => 404,
				'body'   => (string) wp_json_encode( array( 'supertab' => true ) ),
			);
		}

		// Bot protection gate: requires both merchant API key and enabled flag.
		// Event reporting additionally requires opt-in analytics.
		$protection_active = $this->settings->has_merchant_api_key()
			&& $this->settings->is_bot_protection_enabled();

		$event_reporting = $protection_active && $this->settings->is_analytics_enabled();

		return array(
			'status' => 200,
			'body'   => (string) wp_json_encode(
				array(
					'runtime'        => null,
					'sdkVersion'     => HttpClient::resolveVersion(),
					'component'      => array(
						'kind'    => self::COMPONENT_KIND,
						'version' => SUPERTAB_CONNECT_VERSION,
					),
					'enforcement'    => $protection_active ? Plugin::get_enforcement_mode()->value : 'disabled',
					'eventReporting' => $event_reporting,
				)
			),
		);
	}

	/**
	 * Verify the challenge token, never letting a failure escape.
	 *
	 * @param string $token             The challenge JWT.
	 * @param string $expected_audience The expected audience (request origin).
	 * @return bool
	 */
	private function verify_challenge( string $token, string $expected_audience ): bool {
		try {
			if ( null !== $this->verify_challenge ) {
				return (bool) ( $this->verify_challenge )( $token, $expected_audience );
			}

			return $this->get_verifier()->verify( $token, $expected_audience );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Lazily build the SDK verifier so normal requests pay no setup cost.
	 *
	 * @return StatusChallengeVerifier
	 */
	private function get_verifier(): StatusChallengeVerifier {
		if ( null === $this->verifier ) {
			$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

			$this->verifier = new StatusChallengeVerifier(
				new JwksProvider( $this->api_base_url, $this->http_client, $debug, new WP_Transient_Cache() ),
				$debug
			);
		}

		return $this->verifier;
	}

	/**
	 * Read the Authorization header. Apache withholds it from the CGI-style
	 * $_SERVER variables, so fall back to the SAPI's raw request headers via
	 * the SDK's resolver ($_SERVER wins when both are present).
	 *
	 * @return string
	 */
	protected function get_authorization_header(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below via sanitize_text_field()/wp_unslash().
		$header = RequestContext::resolveAuthorizationHeader( $_SERVER, $this->get_raw_request_headers() ) ?? '';

		return sanitize_text_field( wp_unslash( $header ) );
	}

	/**
	 * Raw request headers as reported by the SAPI. Overridable seam for tests;
	 * getallheaders() is unavailable under the CLI SAPI.
	 *
	 * @return array<string, string>
	 */
	protected function get_raw_request_headers(): array {
		return function_exists( 'getallheaders' ) ? (array) getallheaders() : array();
	}

	/**
	 * Derive the request origin (scheme://host[:port]) used as the expected
	 * challenge audience. Empty string when the host is unavailable.
	 *
	 * @return string
	 */
	private function get_request_origin(): string {
		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) )
			: '';

		if ( '' === $host ) {
			return '';
		}

		return ( is_ssl() ? 'https' : 'http' ) . '://' . $host;
	}

	/**
	 * Send a JSON response and terminate.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $body        JSON body (already encoded).
	 * @return void
	 */
	private function send_json( int $status_code, string $body ): void {
		status_header( $status_code );
		header( 'Content-Type: application/json' );
		header( 'Cache-Control: no-store' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON produced by wp_json_encode() in build_response().
		echo $body;
		exit;
	}
}
