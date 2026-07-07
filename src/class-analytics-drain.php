<?php
/**
 * Drains buffered analytics events from the object cache and delivers them off-request.
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
 * Recurring background reader for the {@see Analytics_Buffer}. Scans closed time
 * buckets, POSTs their events to the relay, and deletes the drained keys.
 * Delete-after-attempt, deliver-once, fail-open throughout.
 */
class Analytics_Drain {

	/**
	 * Recurring drain hook.
	 *
	 * @var string
	 */
	public const HOOK = 'supertab_connect_drain_analytics';

	/**
	 * Legacy per-event hook from the previous Action-Scheduler queue, cleared on deactivation.
	 *
	 * @var string
	 */
	public const LEGACY_HOOK = 'supertab_connect_emit_analytics';

	/**
	 * Custom WP-Cron schedule name for the drain interval (fallback path).
	 *
	 * @var string
	 */
	public const CRON_SCHEDULE = 'supertab_connect_analytics_interval';

	/**
	 * Settings manager.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * HTTP client used for relay POSTs.
	 *
	 * @var HttpClientInterface
	 */
	private HttpClientInterface $http_client;

	/**
	 * Tunables.
	 *
	 * @var Analytics_Config
	 */
	private Analytics_Config $config;

	/**
	 * Constructor.
	 *
	 * @param Settings            $settings    Settings manager.
	 * @param HttpClientInterface $http_client HTTP client for relay POSTs.
	 * @param Analytics_Config    $config      Tunables.
	 */
	public function __construct( Settings $settings, HttpClientInterface $http_client, Analytics_Config $config ) {
		$this->settings    = $settings;
		$this->http_client = $http_client;
		$this->config      = $config;
	}

	/**
	 * Drain closed buckets: collect payloads, deliver in batches, delete drained keys.
	 *
	 * @return void
	 */
	public function drain(): void {
		$current  = intdiv( $this->now(), $this->config->window );
		$deadline = $this->now() + $this->config->max_run;

		$newest = $current - 1 - $this->config->settle;
		$oldest = $current - $this->config->lookback;

		for ( $bucket = $oldest; $bucket <= $newest; $bucket++ ) {
			for ( $shard = 0; $shard < $this->config->shards; $shard++ ) {
				if ( $this->now() >= $deadline ) {
					return;
				}

				$counter_key = Analytics_Buffer::counter_key( $bucket, $shard );
				$counter     = wp_cache_get( $counter_key, Analytics_Buffer::GROUP );

				if ( false === $counter || (int) $counter < 1 ) {
					continue;
				}

				$read_count = min( (int) $counter, $this->config->cap_per_shard );

				$event_keys = array();
				for ( $seq = 1; $seq <= $read_count; $seq++ ) {
					$event_keys[] = Analytics_Buffer::event_key( $bucket, $shard, $seq );
				}

				$values = wp_cache_get_multiple( $event_keys, Analytics_Buffer::GROUP );

				$deliverable = array();
				foreach ( $event_keys as $event_key ) {
					$raw = $values[ $event_key ] ?? false;
					if ( false === $raw ) {
						continue;
					}

					$decoded = json_decode( (string) $raw, true );
					if ( is_array( $decoded ) ) {
						$deliverable[] = array(
							'key'     => $event_key,
							'payload' => $decoded,
						);
					} else {
						// Malformed payload: drop it so it is not re-read.
						wp_cache_delete( $event_key, Analytics_Buffer::GROUP );
					}
				}

				if ( $this->deliver( $deliverable, $deadline ) ) {
					// Deadline reached mid-shard: undelivered event keys and the
					// counter key remain, so this shard re-drains on the next run.
					return;
				}

				// Shard fully delivered — remove its counter key.
				wp_cache_delete( $counter_key, Analytics_Buffer::GROUP );
			}
		}
	}

	/**
	 * Deliver events to the relay one at a time, deleting each key immediately
	 * after its (single) attempt, and stop early when the run budget is spent.
	 *
	 * Per-event delivery today; when the relay's bulk endpoint is available this
	 * becomes one POST per batch (config->batch is reserved for that). Deliver-once
	 * / fail-open: each event is attempted exactly once and its key deleted
	 * regardless of the POST outcome; a mid-run deadline leaves the not-yet-attempted
	 * events untouched for the next run.
	 *
	 * @param array<int, array{key:string, payload:array<string,mixed>}> $items    Event key/payload pairs.
	 * @param int                                                        $deadline UNIX time the run must stop by.
	 * @return bool True if the deadline was reached with items still undelivered.
	 */
	private function deliver( array $items, int $deadline ): bool {
		if ( empty( $items ) ) {
			return false;
		}

		$transport = new HttpAnalyticsTransport(
			$this->settings->get_merchant_api_key(),
			SUPERTAB_CONNECT_API_BASE_URL,
			$this->http_client,
			defined( 'WP_DEBUG' ) && WP_DEBUG
		);

		$last = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			try {
				$transport->emit( AnalyticsEvent::fromArray( $item['payload'] ) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for analytics delivery failures.
					error_log( '[Supertab Connect] Analytics delivery error: ' . $e->getMessage() );
				}
			}

			wp_cache_delete( $item['key'], Analytics_Buffer::GROUP );

			if ( $this->now() >= $deadline && $i < $last ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register the drain runner in every request context, scheduling it idempotently.
	 *
	 * Prefers Action Scheduler (recurring action); falls back to a custom WP-Cron
	 * schedule. Single-concurrency by design — do not whitelist increased concurrency.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'drain' ) );
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval value comes from Analytics_Config::$drain_interval, set in add_cron_interval().
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		if ( $this->action_scheduler_available() ) {
			if ( false === as_next_scheduled_action( self::HOOK, null, Analytics_Buffer::GROUP ) ) {
				as_schedule_recurring_action( $this->now(), $this->config->drain_interval, self::HOOK, array(), Analytics_Buffer::GROUP );
			}
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( $this->now(), self::CRON_SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Register the custom WP-Cron interval used by the fallback path.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Existing schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $this->config->drain_interval,
			'display'  => __( 'Supertab Connect analytics drain interval', 'supertab-connect' ),
		);

		return $schedules;
	}

	/**
	 * Clear the recurring drain job and any legacy per-event jobs. Called on deactivation.
	 *
	 * Args-agnostic: removes every pending event/action for both hooks regardless of
	 * payload. Both hooks are unique to this plugin, so clearing by hook alone is safe.
	 *
	 * @return void
	 */
	public static function clear_scheduled(): void {
		try {
			wp_unschedule_hook( self::HOOK );
			wp_unschedule_hook( self::LEGACY_HOOK );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				call_user_func( 'as_unschedule_all_actions', self::HOOK );
				call_user_func( 'as_unschedule_all_actions', self::LEGACY_HOOK );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for analytics clear_scheduled failures.
				error_log( '[Supertab Connect] Analytics clear_scheduled error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Current UNIX time. Test seam.
	 *
	 * @return int
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * Whether Action Scheduler is available on this site. Test seam.
	 *
	 * @return bool
	 */
	protected function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_recurring_action' );
	}
}
