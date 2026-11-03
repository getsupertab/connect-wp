<?php
/**
 * PKCE helper for the connect flow.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

/**
 * Random tokens and S256 challenges (RFC 7636).
 */
final class Pkce {

	/**
	 * A random token: 32 bytes, base64url without padding (43 characters).
	 *
	 * @return string
	 */
	public static function random_token(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * The S256 challenge for a verifier.
	 *
	 * @param string $verifier PKCE code verifier.
	 * @return string
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Base64url-encode bytes without padding.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE requires base64url.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
