<?php
/**
 * Saved robots.txt handler.
 *
 * Looks up the robots.txt the merchant saved in Supertab Connect and keeps
 * the last answer in a transient, so robots.txt requests rarely reach the API.
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
 * Fetches and caches the robots.txt saved in Supertab Connect.
 */
class Saved_Robots_Txt_Handler {

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
			$record = $this->fetch( null !== $record ? $record['body'] : null );
			set_transient( self::CACHE_TRANSIENT_KEY, $record, self::RECORD_TTL );
		}

		return array(
			'body'   => $record['body'],
			'failed' => $record['failed'],
		);
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
