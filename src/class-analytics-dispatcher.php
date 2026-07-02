<?php
/**
 * Queues analytics events and dispatches them off the visitor request.
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
 * Routes analytics events through a WordPress job queue so the POST to the
 * Supertab Connect relay happens off the visitor request.
 */
class Analytics_Dispatcher {

	/**
	 * Hook that carries a single serialized analytics event.
	 *
	 * @var string
	 */
	private const HOOK = 'supertab_connect_emit_analytics';

	/**
	 * Action Scheduler group for enqueued events.
	 *
	 * @var string
	 */
	private const GROUP = 'supertab-connect';

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
	 * Constructor.
	 *
	 * @param Settings            $settings    Settings manager.
	 * @param HttpClientInterface $http_client HTTP client for relay POSTs.
	 */
	public function __construct( Settings $settings, HttpClientInterface $http_client ) {
		$this->settings    = $settings;
		$this->http_client = $http_client;
	}

	/**
	 * Register the queued-job handler.
	 *
	 * Must run in every request context (admin, front-end, cron) so the queue
	 * runner can dispatch wherever it executes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'dispatch' ) );
	}

	/**
	 * Clear any pending queued work. Called on plugin deactivation.
	 *
	 * Clearing is args-agnostic: it removes every pending event/action for
	 * {@see self::HOOK} regardless of the payload it was scheduled with. The
	 * hook is unique to this plugin, so clearing by hook alone is safe. Note
	 * that exact-args clearing (e.g. {@see wp_clear_scheduled_hook()} with no
	 * args) would match nothing, since every event is scheduled with a
	 * non-empty payload.
	 *
	 * @return void
	 */
	public static function clear_scheduled(): void {
		wp_unschedule_hook( self::HOOK );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			call_user_func( 'as_unschedule_all_actions', self::HOOK );
		}
	}

	/**
	 * Enqueue a serialized analytics event for off-request delivery.
	 *
	 * Prefers Action Scheduler (near-real-time async loopback); falls back to
	 * WP-Cron; and, only if scheduling fails outright, emits inline as a
	 * best-effort last resort so events are never silently dropped.
	 *
	 * @param array<string, mixed> $event_data Serialized {@see AnalyticsEvent}.
	 * @return void
	 */
	public function enqueue( array $event_data ): void {
		if ( $this->action_scheduler_available() ) {
			$this->enqueue_async( $event_data );
			return;
		}

		if ( $this->enqueue_cron( $event_data ) ) {
			return;
		}

		$this->dispatch( $event_data );
	}

	/**
	 * Deliver one event to the relay.
	 *
	 * Serves as both the queued job handler and the inline last-resort path.
	 * Fail-open: rehydration ({@see AnalyticsEvent::fromArray()}) and delivery
	 * are wrapped so a malformed payload can never throw into the queue runner
	 * or the visitor request; {@see HttpAnalyticsTransport} additionally
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
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for analytics dispatch failures.
				error_log( '[Supertab Connect] Analytics dispatch error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Whether Action Scheduler is available on this site.
	 *
	 * @return bool
	 */
	protected function action_scheduler_available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Enqueue via Action Scheduler's async action (runs ASAP in a loopback).
	 *
	 * @param array<string, mixed> $event_data Serialized event.
	 * @return void
	 */
	protected function enqueue_async( array $event_data ): void {
		call_user_func( 'as_enqueue_async_action', self::HOOK, array( $event_data ), self::GROUP );
	}

	/**
	 * Enqueue via WP-Cron as a single event at the earliest tick.
	 *
	 * @param array<string, mixed> $event_data Serialized event.
	 * @return bool True if the event was scheduled.
	 */
	protected function enqueue_cron( array $event_data ): bool {
		return false !== wp_schedule_single_event( time(), self::HOOK, array( $event_data ) );
	}
}
