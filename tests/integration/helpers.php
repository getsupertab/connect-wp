<?php
/**
 * Shared helpers for integration tests.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

namespace Supertab_Connect\Tests\Integration;

/**
 * Thrown from the wp_redirect filter so a handler's exit is never reached.
 */
final class Redirect_Caught extends \Exception {

	public string $location;

	public function __construct( string $location ) {
		parent::__construct( $location );
		$this->location = $location;
	}
}

/**
 * Turns wp_redirect() calls into Redirect_Caught exceptions.
 */
function catch_redirects(): void {
	add_filter(
		'wp_redirect',
		static function ( string $location ): string {
			throw new Redirect_Caught( $location );
		},
		PHP_INT_MAX
	);
}

/**
 * The query parameters of a URL.
 *
 * @return array<string, string>
 */
function query_of( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	return $query;
}
