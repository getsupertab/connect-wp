<?php
/**
 * Captures analytics events into the object cache, off the request's DB/network path.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one event per unique object-cache key, sequenced by an atomic per-shard
 * counter. No read-modify-write (race-free) and no single object near the cache's
 * per-object size limit. Fail-open: any shortfall silently drops the event.
 */
class Analytics_Buffer {

	/**
	 * Object-cache group for all buffered analytics keys.
	 *
	 * @var string
	 */
	public const GROUP = 'supertab-connect-analytics';

	/**
	 * Tunables.
	 *
	 * @var Analytics_Config
	 */
	private Analytics_Config $config;

	/**
	 * Constructor.
	 *
	 * @param Analytics_Config $config Tunables.
	 */
	public function __construct( Analytics_Config $config ) {
		$this->config = $config;
	}

	/**
	 * Buffer one serialized analytics event for later batched delivery.
	 *
	 * @param array<string, mixed> $event_data Serialized AnalyticsEvent.
	 * @return void
	 */
	public function capture( array $event_data ): void {
		$bucket = intdiv( $this->now(), $this->config->window );
		$shard  = $this->pick_shard();

		$counter_key = self::counter_key( $bucket, $shard );

		// Seed the counter atomically (no-op if present), then increment atomically.
		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- TTL is Analytics_Config::$ttl, derived from lookback/window and always a positive number of seconds.
		wp_cache_add( $counter_key, 0, self::GROUP, $this->config->ttl );
		$seq = wp_cache_incr( $counter_key, 1, self::GROUP );

		// Shed if the counter is unavailable or we are over the per-shard cap.
		if ( false === $seq || $seq > $this->config->cap_per_shard ) {
			return;
		}

		$json = wp_json_encode( $event_data );
		if ( false === $json ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- TTL is Analytics_Config::$ttl, derived from lookback/window and always a positive number of seconds.
		wp_cache_set( self::event_key( $bucket, $shard, (int) $seq ), $json, self::GROUP, $this->config->ttl );
	}

	/**
	 * Counter key for a bucket+shard.
	 *
	 * @param int $bucket Time bucket.
	 * @param int $shard  Shard index.
	 * @return string
	 */
	public static function counter_key( int $bucket, int $shard ): string {
		return 'ctr_' . $bucket . '_' . $shard;
	}

	/**
	 * Payload key for a bucket+shard+sequence.
	 *
	 * @param int $bucket Time bucket.
	 * @param int $shard  Shard index.
	 * @param int $seq    Sequence within the bucket+shard.
	 * @return string
	 */
	public static function event_key( int $bucket, int $shard, int $seq ): string {
		return 'ev_' . $bucket . '_' . $shard . '_' . $seq;
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
	 * Pick a shard uniformly at random. Test seam.
	 *
	 * @return int
	 */
	protected function pick_shard(): int {
		return random_int( 0, $this->config->shards - 1 );
	}
}
