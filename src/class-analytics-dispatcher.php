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
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
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
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	private const GROUP = 'supertab-connect';

	/**
	 * Maximum events per batch POST (API limit: 500/request).
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Maximum batches per flush run, bounding job runtime.
	 *
	 * @var int
	 */
	private const MAX_BATCHES_PER_RUN = 10;

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
	 * Register job handlers and (in admin/cron contexts) self-heal the schema
	 * and the hourly schedule.
	 *
	 * Handlers must be registered in every request context (admin, front-end,
	 * cron) so the queue runner can dispatch wherever it executes. Schema
	 * install and schedule checks are restricted to admin/cron requests to
	 * keep front-end requests free of extra queries.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::FLUSH_HOOK, array( $this, 'flush' ) );
		add_action( self::LEGACY_HOOK, array( $this, 'dispatch' ) );

		if ( is_admin() || wp_doing_cron() ) {
			try {
				$this->table->install();
			} catch ( \Throwable $e ) {
				self::log_debug( 'Analytics schema install error: ' . $e->getMessage() );
			}

			// Deferred to init: Action Scheduler's data store is only usable
			// from init priority 1 onward, and register() runs at plugins_loaded
			// — as_*() calls made that early silently schedule nothing.
			add_action( 'init', array( $this, 'ensure_scheduled' ) );
		}
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
	 * Ensure the hourly flush is scheduled exactly once, preferring Action
	 * Scheduler and adapting when it appears or disappears.
	 *
	 * Runs on init (hooked by {@see register()}) because Action Scheduler's
	 * data store initializes on init priority 1; called earlier, its API
	 * functions return without scheduling.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		try {
			if ( $this->action_scheduler_available() ) {
				// Migrate a stale WP-Cron recurrence so both backends never fire.
				if ( false !== wp_next_scheduled( self::FLUSH_HOOK ) ) {
					wp_clear_scheduled_hook( self::FLUSH_HOOK );
				}

				if ( ! call_user_func( 'as_has_scheduled_action', self::FLUSH_HOOK ) ) {
					call_user_func( 'as_schedule_recurring_action', time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::FLUSH_HOOK, array(), self::GROUP );
				}

				return;
			}

			if ( false === wp_next_scheduled( self::FLUSH_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::FLUSH_HOOK );
			}
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics ensure_scheduled error: ' . $e->getMessage() );
		}
	}

	/**
	 * Whether Action Scheduler's recurring API is available on this site.
	 *
	 * @return bool
	 */
	protected function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' );
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
			if ( $this->table->is_full( self::MAX_BUFFER_ROWS ) ) {
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
	 * Drain the buffer: claim up to BATCH_SIZE rows at a time and POST each
	 * batch as a JSON array, for at most MAX_BATCHES_PER_RUN batches.
	 *
	 * Deliver-once: rows are deleted at claim time, so a failed POST drops
	 * those events (debug-logged). Malformed rows are skipped. Fail-open
	 * throughout — this is the FLUSH_HOOK job handler and must never throw
	 * into the queue runner.
	 *
	 * @return void
	 */
	public function flush(): void {
		try {
			for ( $i = 0; $i < self::MAX_BATCHES_PER_RUN; $i++ ) {
				$payloads = $this->table->claim_batch( self::BATCH_SIZE );

				if ( array() === $payloads ) {
					return;
				}

				$events = array();
				foreach ( $payloads as $payload ) {
					$event = json_decode( $payload, true );

					if ( is_array( $event ) ) {
						$events[] = $event;
					} else {
						self::log_debug( 'Skipping malformed buffered analytics event.' );
					}
				}

				if ( array() !== $events ) {
					$this->post_batch( $events );
				}

				if ( count( $payloads ) < self::BATCH_SIZE ) {
					return;
				}
			}
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics flush error: ' . $e->getMessage() );
		}
	}

	/**
	 * POST one batch of events to the relay as a JSON array.
	 *
	 * Best-effort: non-2xx responses, rejected events, and transport errors
	 * are debug-logged only — never retried or re-buffered.
	 *
	 * @param array<int, array<string, mixed>> $events Decoded event payloads.
	 * @return void
	 */
	private function post_batch( array $events ): void {
		try {
			$body = wp_json_encode( array_values( $events ) );

			if ( false === $body ) {
				self::log_debug( 'Failed to encode analytics batch.' );
				return;
			}

			$response = $this->http_client->post(
				rtrim( SUPERTAB_CONNECT_API_BASE_URL, '/' ) . AnalyticsTransportInterface::ANALYTICS_EVENTS_PATH,
				$body,
				array(
					'Authorization' => 'Bearer ' . $this->settings->get_merchant_api_key(),
					'Content-Type'  => 'application/json',
				)
			);

			if ( $response['statusCode'] < 200 || $response['statusCode'] >= 300 ) {
				self::log_debug( 'Analytics batch POST returned ' . $response['statusCode'] . '; ' . count( $events ) . ' events dropped.' );
				return;
			}

			$decoded = json_decode( $response['body'], true );
			if ( is_array( $decoded ) && ( $decoded['rejected_count'] ?? 0 ) > 0 ) {
				self::log_debug( 'Analytics batch partially rejected: ' . $decoded['rejected_count'] . ' events dropped server-side.' );
			}
		} catch ( \Throwable $e ) {
			self::log_debug( 'Analytics batch POST error: ' . $e->getMessage() . '; ' . count( $events ) . ' events dropped.' );
		}
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
