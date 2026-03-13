<?php
/**
 * Minimal WordPress function stubs for unit testing.
 *
 * Provides in-memory implementations of WordPress functions
 * used by the plugin, so tests run without a full WordPress install.
 *
 * @package Supertab_Connect\Tests
 */

declare( strict_types=1 );

// phpcs:disable -- Test helper, not production code.

/*
|--------------------------------------------------------------------------
| WordPress Constants
|--------------------------------------------------------------------------
*/

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/*
|--------------------------------------------------------------------------
| Plugin Constants
|--------------------------------------------------------------------------
*/

if ( ! defined( 'SUPERTAB_CONNECT_VERSION' ) ) {
	define( 'SUPERTAB_CONNECT_VERSION', '0.1.0-test' );
}

if ( ! defined( 'SUPERTAB_CONNECT_PLUGIN_FILE' ) ) {
	define( 'SUPERTAB_CONNECT_PLUGIN_FILE', dirname( __DIR__ ) . '/supertab-connect.php' );
}

if ( ! defined( 'SUPERTAB_CONNECT_PLUGIN_DIR' ) ) {
	define( 'SUPERTAB_CONNECT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'SUPERTAB_CONNECT_PLUGIN_URL' ) ) {
	define( 'SUPERTAB_CONNECT_PLUGIN_URL', 'https://example.com/wp-content/plugins/supertab-connect/' );
}

if ( ! defined( 'SUPERTAB_CONNECT_ENVIRONMENT' ) ) {
	define( 'SUPERTAB_CONNECT_ENVIRONMENT', 'sbx' );
}

if ( ! defined( 'SUPERTAB_CONNECT_API_BASE_URL' ) ) {
	define( 'SUPERTAB_CONNECT_API_BASE_URL', 'https://api-connect.sbx.supertab.co' );
}

/*
|--------------------------------------------------------------------------
| In-Memory Store
|--------------------------------------------------------------------------
|
| A simple key-value store shared by options and transients stubs.
| Tests can call wp_stubs_reset() to clear state between tests.
|
*/

global $wp_test_options, $wp_test_transients, $wp_test_headers_sent, $wp_test_status_code;

$wp_test_options     = [];
$wp_test_transients  = [];
$wp_test_headers_sent = [];
$wp_test_status_code = 200;

/**
 * Reset all in-memory stores. Call in setUp()/tearDown().
 */
function wp_stubs_reset(): void {
	global $wp_test_options, $wp_test_transients, $wp_test_headers_sent, $wp_test_status_code;
	$wp_test_options      = [];
	$wp_test_transients   = [];
	$wp_test_headers_sent = [];
	$wp_test_status_code  = 200;
}

/*
|--------------------------------------------------------------------------
| Options API
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		global $wp_test_options;
		return $wp_test_options[ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		global $wp_test_options;
		$wp_test_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		global $wp_test_options;
		unset( $wp_test_options[ $option ] );
		return true;
	}
}

/*
|--------------------------------------------------------------------------
| Transients API
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		global $wp_test_transients;
		return $wp_test_transients[ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		global $wp_test_transients;
		$wp_test_transients[ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		global $wp_test_transients;
		unset( $wp_test_transients[ $transient ] );
		return true;
	}
}

/*
|--------------------------------------------------------------------------
| HTTP Response Stubs
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		global $wp_test_status_code;
		$wp_test_status_code = $code;
	}
}

/*
|--------------------------------------------------------------------------
| Escaping / Sanitization
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

/*
|--------------------------------------------------------------------------
| Hooks (no-op stubs)
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}
