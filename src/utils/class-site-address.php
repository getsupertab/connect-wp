<?php
/**
 * Host and origin of a URL, as browsers compare them.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Utils;

/**
 * Lowercase host, with the port only when it isn't the scheme's default.
 */
final class Site_Address {

	private const DEFAULT_PORTS = array(
		'http'  => 80,
		'https' => 443,
	);

	/**
	 * The host and non-default port, e.g. "news.example.com" or "localhost:8888"; empty if unparsable.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$host   = strtolower( $parts['host'] );
		$scheme = strtolower( $parts['scheme'] ?? '' );
		$port   = $parts['port'] ?? null;
		if ( null !== $port && ( self::DEFAULT_PORTS[ $scheme ] ?? null ) !== $port ) {
			$host .= ':' . $port;
		}

		return $host;
	}

	/**
	 * Scheme, host and non-default port, e.g. "https://news.example.com"; empty if unparsable.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( string $url ): string {
		$host = self::host( $url );
		if ( '' === $host ) {
			return '';
		}

		return strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) . '://' . $host;
	}
}
