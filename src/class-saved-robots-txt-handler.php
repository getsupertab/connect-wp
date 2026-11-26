<?php
/**
 * Saved robots.txt handler.
 *
 * Answers /robots.txt with the file the merchant saved in Supertab Connect,
 * when they turned that on. Keeps the last answer from the API in a transient,
 * so robots.txt requests rarely reach the API, and lets WordPress serve its own
 * robots.txt whenever there is no saved file to send.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab\Connect\Http\HttpClientInterface;

/**
 * Serves the robots.txt saved in Supertab Connect.
 */
class Saved_Robots_Txt_Handler {

	/**
	 * Status: the setting is off, or no Website URN is saved.
	 *
	 * @var string
	 */
	public const STATUS_OFF = 'off';

	/**
	 * Status: WordPress lives in a subdirectory, so it doesn't answer the domain's robots.txt.
	 *
	 * @var string
	 */
	public const STATUS_SUBDIRECTORY = 'subdirectory';

	/**
	 * Status: "Discourage search engines from indexing this site" is on.
	 *
	 * @var string
	 */
	public const STATUS_DISCOURAGED = 'discouraged';

	/**
	 * Status: the saved file is served.
	 *
	 * @var string
	 */
	public const STATUS_SERVING = 'serving';

	/**
	 * Status: nothing is saved in Supertab Connect, so WordPress serves its own file.
	 *
	 * @var string
	 */
	public const STATUS_NOT_SAVED = 'not_saved';

	/**
	 * Status: the API couldn't be reached; the last copy received is served.
	 *
	 * @var string
	 */
	public const STATUS_UNREACHABLE_STALE = 'unreachable_stale';

	/**
	 * Status: the API couldn't be reached and there is no copy, so WordPress serves its own file.
	 *
	 * @var string
	 */
	public const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * The request path this handler intercepts.
	 *
	 * @var string
	 */
	private const REQUEST_PATH = 'robots.txt';

	/**
	 * Transient key for the cached lookup record.
	 *
	 * @var string
	 */
	public const CACHE_TRANSIENT_KEY = 'supertab_connect_robots_txt';

	/**
	 * Timeout for the API request, in seconds.
	 *
	 * @var int
	 */
	public const FETCH_TIMEOUT = 3;

	/**
	 * How long an answer from the API stays fresh, in seconds.
	 *
	 * @var int
	 */
	private const FRESH_FOR = 300;

	/**
	 * How long to wait before asking again after a failed request, in seconds.
	 *
	 * @var int
	 */
	private const RETRY_AFTER_FAILURE = 60;

	/**
	 * Transient expiry, in seconds (30 days), so the record outlasts its freshness
	 * and a previous copy is still there when the API is down.
	 *
	 * @var int
	 */
	private const RECORD_TTL = 2592000;

	/**
	 * Largest body accepted as a robots.txt, in bytes (1 MB).
	 *
	 * @var int
	 */
	private const MAX_BODY_BYTES = 1048576;

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
	 * HTTP client for the API request.
	 *
	 * @var HttpClientInterface
	 */
	private HttpClientInterface $http_client;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings     Settings manager.
	 * @param string              $api_base_url Base URL for the Supertab Connect API.
	 * @param HttpClientInterface $http_client  HTTP client for the API request.
	 */
	public function __construct( Settings $settings, string $api_base_url, HttpClientInterface $http_client ) {
		$this->settings     = $settings;
		$this->api_base_url = $api_base_url;
		$this->http_client  = $http_client;
	}

	/**
	 * Register hooks.
	 *
	 * Runs at the default priority, after Status_Handler and Bot_Protection.
	 * Bot_Protection lets robots.txt through, so crawlers always reach it.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve_robots_txt' ), 10 );
	}

	/**
	 * Serve the saved robots.txt if the current request is for robots.txt and there is one to serve.
	 *
	 * Otherwise returns, and WordPress answers the request as it would without this plugin.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function maybe_serve_robots_txt( \WP $wp ): void {
		if ( self::REQUEST_PATH !== $wp->request ) {
			return;
		}

		$body = $this->body_to_serve();
		if ( null === $body ) {
			return;
		}

		$this->send( $body );
	}

	/**
	 * Why the saved file isn't served, before looking it up.
	 *
	 * Never makes a request.
	 *
	 * @return string|null STATUS_OFF, STATUS_SUBDIRECTORY or STATUS_DISCOURAGED, in that
	 *                     order, or null when nothing stands in the way.
	 */
	public function blocked_by(): ?string {
		if ( ! $this->settings->is_robots_txt_serving_enabled() || ! $this->settings->has_website_urn() ) {
			return self::STATUS_OFF;
		}

		if ( '' !== trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) ) {
			return self::STATUS_SUBDIRECTORY;
		}

		// A staging copy of a production site carries the setting; hosts discourage search engines there.
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return self::STATUS_DISCOURAGED;
		}

		return null;
	}

	/**
	 * What /robots.txt answers with, as one of the STATUS_* constants.
	 *
	 * May make the one request when the cached record is stale.
	 *
	 * @return string
	 */
	public function status(): string {
		$blocked_by = $this->blocked_by();
		if ( null !== $blocked_by ) {
			return $blocked_by;
		}

		$lookup = $this->lookup();
		if ( null !== $lookup['body'] ) {
			return $lookup['failed'] ? self::STATUS_UNREACHABLE_STALE : self::STATUS_SERVING;
		}

		return $lookup['failed'] ? self::STATUS_UNREACHABLE : self::STATUS_NOT_SAVED;
	}

	/**
	 * The saved file to send for /robots.txt, or null to let WordPress serve its own.
	 *
	 * @return string|null
	 */
	public function body_to_serve(): ?string {
		if ( null !== $this->blocked_by() ) {
			return null;
		}

		return $this->lookup()['body'];
	}

	/**
	 * The saved robots.txt, from the cached record or, when that is stale, from the API.
	 *
	 * A failed request keeps the previous copy, if there is one, and isn't
	 * repeated for a minute.
	 *
	 * @return array{body: ?string, failed: bool} The saved file (null when nothing is saved
	 *                                            or none could be fetched), and whether the
	 *                                            last request failed.
	 */
	public function lookup(): array {
		if ( ! $this->settings->has_website_urn() ) {
			return array(
				'body'   => null,
				'failed' => false,
			);
		}

		$record = self::read_record();
		if ( null === $record || $record['retry_at'] <= time() ) {
			$previous_body = null !== $record ? $record['body'] : null;

			if ( null !== $record ) {
				// Claim the refresh, so requests arriving while it runs keep the current copy
				// instead of all waiting on the API. Lapses soon if this request dies mid-fetch.
				$record['retry_at'] = time() + 2 * self::FETCH_TIMEOUT;
				set_transient( self::CACHE_TRANSIENT_KEY, $record, self::RECORD_TTL );
			}

			$record = $this->fetch( $previous_body );
			set_transient( self::CACHE_TRANSIENT_KEY, $record, self::RECORD_TTL );
		}

		return array(
			'body'   => $record['body'],
			'failed' => $record['failed'],
		);
	}

	/**
	 * Have the next lookup ask the API again, keeping the cached copy to serve if that fails.
	 *
	 * @return void
	 */
	public static function mark_stale(): void {
		$record = self::read_record();
		if ( null === $record ) {
			return;
		}

		$record['retry_at'] = 0;
		set_transient( self::CACHE_TRANSIENT_KEY, $record, self::RECORD_TTL );
	}

	/**
	 * Ask the API for the saved robots.txt.
	 *
	 * @param string|null $previous_body The copy to keep if the request fails.
	 * @return array{body: ?string, failed: bool, retry_at: int} The new record.
	 */
	private function fetch( ?string $previous_body ): array {
		$url = rtrim( $this->api_base_url, '/' ) . '/merchants/systems/' . $this->settings->get_website_urn() . '/robots.txt';

		try {
			$response = $this->http_client->get( $url );
		} catch ( \Throwable $e ) {
			return self::record( $previous_body, true );
		}

		if ( 404 === $response['statusCode'] ) {
			return self::record( null, false );
		}

		if ( 200 === $response['statusCode'] && self::is_robots_txt( $response['body'] ) ) {
			return self::record( $response['body'], false );
		}

		return self::record( $previous_body, true );
	}

	/**
	 * Whether a 200 response body can be served as robots.txt.
	 *
	 * An HTML page in its place comes from a proxy or firewall on the way to the API.
	 *
	 * @param string $body The response body.
	 * @return bool
	 */
	private static function is_robots_txt( string $body ): bool {
		$trimmed = ltrim( $body );

		return '' !== $trimmed && strlen( $body ) <= self::MAX_BODY_BYTES && '<' !== $trimmed[0];
	}

	/**
	 * Build a record, fresh for five minutes after an answer or one minute after a failure.
	 *
	 * @param string|null $body   The saved file, or null.
	 * @param bool        $failed Whether the request failed.
	 * @return array{body: ?string, failed: bool, retry_at: int}
	 */
	private static function record( ?string $body, bool $failed ): array {
		return array(
			'body'     => $body,
			'failed'   => $failed,
			'retry_at' => time() + ( $failed ? self::RETRY_AFTER_FAILURE : self::FRESH_FOR ),
		);
	}

	/**
	 * Send the saved robots.txt exactly as received and terminate.
	 *
	 * @param string $body The saved file.
	 * @return void
	 */
	private function send( string $body ): void {
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text robots.txt served verbatim with a text/plain type; the API validates it on save.
		echo $body;
		exit;
	}

	/**
	 * The cached record, or null when there is none or it isn't the shape this class writes.
	 *
	 * @return array{body: ?string, failed: bool, retry_at: int}|null
	 */
	private static function read_record(): ?array {
		$record = get_transient( self::CACHE_TRANSIENT_KEY );

		if (
			! is_array( $record )
			|| ! array_key_exists( 'body', $record )
			|| ! ( is_string( $record['body'] ) || null === $record['body'] )
			|| ! isset( $record['failed'], $record['retry_at'] )
			|| ! is_bool( $record['failed'] )
			|| ! is_int( $record['retry_at'] )
		) {
			return null;
		}

		return array(
			'body'     => $record['body'],
			'failed'   => $record['failed'],
			'retry_at' => $record['retry_at'],
		);
	}
}
