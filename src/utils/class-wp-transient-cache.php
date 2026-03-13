<?php
/**
 * WordPress transient cache adapter for the Supertab Connect SDK.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Utils;

use Supertab\Connect\Cache\CacheInterface;

/**
 * Cache implementation backed by WordPress transients.
 */
class WP_Transient_Cache implements CacheInterface {

	/**
	 * Get a cached value by key.
	 *
	 * @param string $key Cache key.
	 * @return string|null The cached value, or null if not found or expired.
	 */
	public function get( string $key ): ?string {
		$value = get_transient( $key );

		if ( false === $value ) {
			return null;
		}

		return (string) $value;
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key.
	 * @param string $value Value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 */
	public function set( string $key, string $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}
}
