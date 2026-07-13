<?php
/**
 * Buffers analytics events into a custom table and delivers them to the
 * Supertab Connect relay in hourly batches.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab\Connect\Http\HttpClientInterface;

/**
 * Routes analytics events through a table buffer drained by an hourly
 * recurring job, so batched POSTs to the relay happen off visitor requests.
 *
 * Deliver-once, fail-open: rows are claimed (deleted) before sending; any
 * failure drops events with a debug log and never throws into a visitor
 * request or the queue runner.
 */
class Analytics_Dispatcher {

	/**
	 * Recurring hook that drains the buffer.
	 *
	 * @var string
	 */
	public const FLUSH_HOOK = 'supertab_connect_flush_analytics';

	/**
	 * Pre-1.4 per-event hook; still registered so jobs queued by an earlier
	 * plugin version drain gracefully, and cleared on deactivation.
	 *
	 * @var string
	 */
	public const LEGACY_HOOK = 'supertab_connect_emit_analytics';

	/**
	 * Row cap: when the buffer holds this many rows (broken cron), new events
	 * are dropped rather than growing the table unboundedly.
	 *
	 * @var int
	 */
	private const MAX_BUFFER_ROWS = 10000;

	/**
	 * Settings manager.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * HTTP client used to POST events when a job runs.
	 *
	 * @var HttpClientInterface
	 */
	private HttpClientInterface $http_client;

	/**
	 * Queue table.
	 *
	 * @var Analytics_Queue_Table
	 */
	private Analytics_Queue_Table $table;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings    Settings manager.
	 * @param HttpClientInterface   $http_client HTTP client for relay POSTs.
	 * @param Analytics_Queue_Table $table       Buffer table.
	 */
	public function __construct( Settings $settings, HttpClientInterface $http_client, Analytics_Queue_Table $table ) {
		$this->settings    = $settings;
		$this->http_client = $http_client;
		$this->table       = $table;
	}

	/**
	 * Register job handlers.
	 *
	 * Must run in every request context (admin, front-end, cron) so the queue
	 * runner can dispatch wherever it executes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::LEGACY_HOOK, array( $this, 'dispatch' ) );
	}

	/**
	 * Clear any pending queued work. Called on plugin deactivation.
	 *
	 * Clears both the recurring flush hook and the legacy per-event hook, in
	 * both backends, args-agnostically.
	 *
	 * @return void
	 */
	public static function clear_scheduled(): void {
		try {
			foreach ( array( self::FLUSH_HOOK, self::LEGACY_HOOK ) as $hook ) {
				wp_unschedule_hook( $hook );

				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					call_user_func( 'as_unschedule_all_actions', $hook );
				}
			}
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics clear_scheduled error: ' . $e->getMessage() );
		}
	}

	/**
	 * Buffer a serialized analytics event for the next hourly batch flush.
	 *
	 * Fail-open: on a full buffer the event is dropped; on any insert/encode
	 * failure (e.g. missing table) delivery falls back to the inline
	 * single-event path so the event still has a chance to arrive.
	 *
	 * @param array<string, mixed> $event_data Serialized {@see AnalyticsEvent}.
	 * @return void
	 */
	public function enqueue( array $event_data ): void {
		try {
			if ( $this->table->count() >= self::MAX_BUFFER_ROWS ) {
				self::log_debug( 'Analytics buffer full; dropping event.' );
				return;
			}

			$payload = wp_json_encode( $event_data );

			if ( false !== $payload && $this->table->insert( $payload ) ) {
				return;
			}
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics enqueue error: ' . $e->getMessage() );
		}

		$this->dispatch( $event_data );
	}

	/**
	 * Deliver one event to the relay, inline.
	 *
	 * Serves as the legacy queued-job handler and the buffering last-resort
	 * path. Fail-open: rehydration and delivery are wrapped so a malformed
	 * payload can never throw; {@see HttpAnalyticsTransport} additionally
	 * swallows transport errors.
	 *
	 * @param array<string, mixed> $event_data Serialized {@see AnalyticsEvent}.
	 * @return void
	 */
	public function dispatch( array $event_data ): void {
		try {
			$transport = new HttpAnalyticsTransport(
				$this->settings->get_merchant_api_key(),
				SUPERTAB_CONNECT_API_BASE_URL,
				$this->http_client,
				defined( 'WP_DEBUG' ) && WP_DEBUG
			);

			$transport->emit( AnalyticsEvent::fromArray( $event_data ) );
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics dispatch error: ' . $e->getMessage() );
		}
	}

	/**
	 * Log a message when WP_DEBUG is on.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private static function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging; analytics is fail-open.
			error_log( '[Supertab Connect] ' . $message );
		}
	}
}
