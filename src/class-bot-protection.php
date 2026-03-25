<?php
/**
 * Bot traffic protection handler.
 *
 * Intercepts incoming requests and uses the Supertab Connect SDK
 * to detect bots and enforce license token requirements.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab\Connect\Result\BlockResult;
use Supertab\Connect\SupertabConnect;

/**
 * Protects front-end requests from unauthorized bot traffic.
 */
class Bot_Protection {

	/**
	 * The request path to exclude from bot protection.
	 *
	 * @var string
	 */
	private const EXCLUDED_PATH = 'license.xml';

	/**
	 * SupertabConnect SDK instance.
	 *
	 * @var SupertabConnect
	 */
	private SupertabConnect $supertab_connect;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Signal headers to add to the response.
	 *
	 * @var array<string, string>
	 */
	private array $signal_headers = array();

	/**
	 * Constructor.
	 *
	 * @param SupertabConnect $supertab_connect SDK instance for request handling.
	 * @param Settings        $settings         Settings manager.
	 */
	public function __construct( SupertabConnect $supertab_connect, Settings $settings ) {
		$this->supertab_connect = $supertab_connect;
		$this->settings         = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_handle_request' ), 9 );
		add_filter( 'wp_headers', array( $this, 'add_signal_headers' ) );
	}

	/**
	 * Handle bot detection for the current request.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function maybe_handle_request( \WP $wp ): void {
		if ( self::EXCLUDED_PATH === $wp->request ) {
			return;
		}

		if ( ! $this->is_path_active( $wp->request ) ) {
			return;
		}

		try {
			$result = $this->supertab_connect->handleRequest();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for SDK failures.
			error_log( '[Supertab Connect] Bot protection error: ' . $e->getMessage() );
			return;
		}

		if ( $result instanceof BlockResult ) {
			$this->send_block_response( $result );
			return;
		}

		$this->signal_headers = $result->headers;
	}

	/**
	 * Add signal headers to the WordPress response.
	 *
	 * @param array<string, string> $headers Existing WordPress response headers.
	 * @return array<string, string>
	 */
	public function add_signal_headers( array $headers ): array {
		return array_merge( $headers, $this->signal_headers );
	}

	/**
	 * Check if the given request path matches any active path pattern.
	 *
	 * @param string $request_path The request path to check.
	 * @return bool True if the path is active.
	 */
	private function is_path_active( string $request_path ): bool {
		$active_paths    = $this->settings->get_active_paths();
		$normalized_path = rtrim( $request_path, '/' );

		foreach ( $active_paths as $pattern ) {
			if ( '*' === $pattern ) {
				return true;
			}

			$normalized_pattern = rtrim( $pattern, '/' );

			if ( fnmatch( $normalized_pattern, $normalized_path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Send a block response and terminate.
	 *
	 * @param BlockResult $result The block result from the SDK.
	 * @return void
	 */
	private function send_block_response( BlockResult $result ): void {
		status_header( $result->status );

		foreach ( $result->headers as $name => $value ) {
			header( str_replace( array( "\r", "\n" ), '', "{$name}: {$value}" ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Response body from SDK, must be served verbatim.
		echo $result->body;
		exit;
	}
}
