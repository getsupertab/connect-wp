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

		$payloads = array();
		$keys     = array();

		for ( $bucket = $oldest; $bucket <= $newest; $bucket++ ) {
			for ( $shard = 0; $shard < $this->config->shards; $shard++ ) {
				if ( $this->now() >= $deadline ) {
					break 2;
				}

				$counter_key = Analytics_Buffer::counter_key( $bucket, $shard );
				$counter     = wp_cache_get( $counter_key, Analytics_Buffer::GROUP );

				if ( false === $counter || (int) $counter < 1 ) {
					continue;
				}

				$keys[]     = $counter_key;
				$read_count = min( (int) $counter, $this->config->cap_per_shard );

				$event_keys = array();
				for ( $seq = 1; $seq <= $read_count; $seq++ ) {
					$event_keys[] = Analytics_Buffer::event_key( $bucket, $shard, $seq );
				}

				$values = wp_cache_get_multiple( $event_keys, Analytics_Buffer::GROUP );

				foreach ( $event_keys as $event_key ) {
					$keys[] = $event_key;

					$raw = $values[ $event_key ] ?? false;
					if ( false !== $raw ) {
						$decoded = json_decode( (string) $raw, true );
						if ( is_array( $decoded ) ) {
							$payloads[] = $decoded;
						}
					}

					if ( count( $payloads ) >= $this->config->batch ) {
						$this->deliver( $payloads );
						wp_cache_delete_multiple( $keys, Analytics_Buffer::GROUP );
						$payloads = array();
						$keys     = array();

						if ( $this->now() >= $deadline ) {
							break 3;
						}
					}
				}
			}
		}

		if ( ! empty( $keys ) ) {
			if ( ! empty( $payloads ) ) {
				$this->deliver( $payloads );
			}
			wp_cache_delete_multiple( $keys, Analytics_Buffer::GROUP );
		}
	}

	/**
	 * Deliver a batch of serialized events to the relay.
	 *
	 * Per-event today; a bulk relay endpoint will collapse this into one POST
	 * when the SDK exposes it. Fail-open per event.
	 *
	 * @param array<int, array<string, mixed>> $payloads Serialized events.
	 * @return void
	 */
	private function deliver( array $payloads ): void {
		$transport = new HttpAnalyticsTransport(
			$this->settings->get_merchant_api_key(),
			SUPERTAB_CONNECT_API_BASE_URL,
			$this->http_client,
			defined( 'WP_DEBUG' ) && WP_DEBUG
		);

		foreach ( $payloads as $payload ) {
			try {
				$transport->emit( AnalyticsEvent::fromArray( $payload ) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging for analytics delivery failures.
					error_log( '[Supertab Connect] Analytics delivery error: ' . $e->getMessage() );
				}
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
