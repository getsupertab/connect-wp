<?php
/**
 * Immutable configuration for the analytics object-cache buffer and drainer.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tunable values for the analytics buffer/drain, resolved from constants.
 */
final class Analytics_Config {

	/**
	 * Constructor.
	 *
	 * @param int $window         Bucket width in seconds.
	 * @param int $shards         Counters (and key shards) per bucket.
	 * @param int $cap_per_shard  Hard cap of events per bucket+shard.
	 * @param int $drain_interval Recurring drain cadence in seconds.
	 * @param int $lookback       Closed buckets the drainer scans (also age-purge horizon).
	 * @param int $settle         Buckets skipped just below the current bucket.
	 * @param int $batch          Events per delivery POST / delete batch.
	 * @param int $max_run        Wall-clock budget per drain run in seconds.
	 * @param int $ttl            Object-cache key TTL in seconds.
	 */
	public function __construct(
		public readonly int $window,
		public readonly int $shards,
		public readonly int $cap_per_shard,
		public readonly int $drain_interval,
		public readonly int $lookback,
		public readonly int $settle,
		public readonly int $batch,
		public readonly int $max_run,
		public readonly int $ttl
	) {}

	/**
	 * Build from SUPERTAB_CONNECT_ANALYTICS_* constants, applying defaults.
	 *
	 * @return self
	 */
	public static function from_constants(): self {
		$window   = self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_WINDOW', 10 );
		$lookback = self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_LOOKBACK', 18 );

		return new self(
			$window,
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_SHARDS', 16 ),
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_CAP_PER_SHARD', 1000 ),
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_DRAIN_INTERVAL', 60 ),
			$lookback,
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_SETTLE', 1 ),
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_BATCH', 100 ),
			self::int_constant( 'SUPERTAB_CONNECT_ANALYTICS_MAX_RUN', 20 ),
			( $lookback * $window ) + 20
		);
	}

	/**
	 * Read a positive integer constant, or return the fallback.
	 *
	 * @param string $name     Constant name.
	 * @param int    $fallback Fallback value.
	 * @return int
	 */
	private static function int_constant( string $name, int $fallback ): int {
		if ( defined( $name ) && is_numeric( constant( $name ) ) && (int) constant( $name ) > 0 ) {
			return (int) constant( $name );
		}

		return $fallback;
	}
}
