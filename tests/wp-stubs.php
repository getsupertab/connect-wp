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

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
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

global $wp_test_options, $wp_test_transients, $wp_test_headers_sent, $wp_test_status_code, $wp_test_http_calls;

$wp_test_options     = [];
$wp_test_transients  = [];
$wp_test_headers_sent = [];
$wp_test_status_code = 200;
$wp_test_http_calls  = [];

global $wp_test_scheduled_events, $wp_test_cleared_hooks, $wp_test_unscheduled_hooks, $wp_test_schedule_result, $wp_test_doing_cron, $wp_test_as_enqueue_calls, $wp_test_as_unschedule_calls;

$wp_test_scheduled_events    = [];
$wp_test_cleared_hooks       = [];
$wp_test_unscheduled_hooks   = [];
$wp_test_schedule_result     = true;
$wp_test_doing_cron          = false;
$wp_test_as_enqueue_calls    = [];
$wp_test_as_unschedule_calls = [];

global $wp_test_as_recurring_calls, $wp_test_as_next_scheduled, $wp_test_wp_next_scheduled;

$wp_test_as_recurring_calls = [];
$wp_test_as_next_scheduled  = false;
$wp_test_wp_next_scheduled  = false;

global $wp_test_object_cache, $wp_test_ext_object_cache;

$wp_test_object_cache     = [];
$wp_test_ext_object_cache = true;

/**
 * Reset all in-memory stores. Call in setUp()/tearDown().
 */
function wp_stubs_reset(): void {
	global $wp_test_options, $wp_test_transients, $wp_test_headers_sent, $wp_test_status_code, $wp_test_http_calls, $wp_test_scheduled_events, $wp_test_cleared_hooks, $wp_test_unscheduled_hooks, $wp_test_schedule_result, $wp_test_doing_cron, $wp_test_as_enqueue_calls, $wp_test_as_unschedule_calls, $wp_test_object_cache, $wp_test_ext_object_cache, $wp_test_as_recurring_calls, $wp_test_as_next_scheduled, $wp_test_wp_next_scheduled;
	$wp_test_options      = [];
	$wp_test_transients   = [];
	$wp_test_headers_sent = [];
	$wp_test_status_code  = 200;
	$wp_test_http_calls   = [];
	$wp_test_scheduled_events   = [];
	$wp_test_cleared_hooks      = [];
	$wp_test_unscheduled_hooks  = [];
	$wp_test_schedule_result    = true;
	$wp_test_doing_cron         = false;
	$wp_test_as_enqueue_calls   = [];
	$wp_test_as_unschedule_calls = [];
	$wp_test_object_cache     = [];
	$wp_test_ext_object_cache = true;
	$wp_test_as_recurring_calls = [];
	$wp_test_as_next_scheduled  = false;
	$wp_test_wp_next_scheduled  = false;
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
| HTTP Request Stubs (WordPress HTTP API)
|--------------------------------------------------------------------------
|
| Capture each outbound request into $wp_test_http_calls so tests can assert
| on the URL and args (headers, user-agent, body). Always returns a canned
| 200 response.
|
*/

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) {
		global $wp_test_http_calls;
		$wp_test_http_calls[] = [ 'method' => 'POST', 'url' => $url, 'args' => $args ];
		return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ) {
		global $wp_test_http_calls;
		$wp_test_http_calls[] = [ 'method' => 'GET', 'url' => $url, 'args' => $args ];
		return [ 'response' => [ 'code' => 200 ], 'body' => 'ok' ];
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
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
| i18n
|--------------------------------------------------------------------------
*/

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
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

/*
|--------------------------------------------------------------------------
| Scheduling (WP-Cron + Action Scheduler) Stubs
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ) {
		global $wp_test_scheduled_events, $wp_test_schedule_result;
		$wp_test_scheduled_events[] = [ 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args ];
		return $wp_test_schedule_result;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = [] ) {
		global $wp_test_cleared_hooks;
		$wp_test_cleared_hooks[] = [ 'hook' => $hook, 'args' => $args ];
		return 0;
	}
}

if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( string $hook, bool $wp_error = false ) {
		global $wp_test_unscheduled_hooks;
		$wp_test_unscheduled_hooks[] = $hook;
		return 0;
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		global $wp_test_doing_cron;
		return (bool) $wp_test_doing_cron;
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( string $hook, array $args = [], string $group = '' ): int {
		global $wp_test_as_enqueue_calls;
		$wp_test_as_enqueue_calls[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
		return count( $wp_test_as_enqueue_calls );
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
		global $wp_test_as_unschedule_calls;
		$wp_test_as_unschedule_calls[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = [], string $group = '' ): int {
		global $wp_test_as_recurring_calls;
		$wp_test_as_recurring_calls[] = [ 'timestamp' => $timestamp, 'interval' => $interval, 'hook' => $hook, 'args' => $args, 'group' => $group ];
		return count( $wp_test_as_recurring_calls );
	}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( string $hook, $args = null, string $group = '' ) {
		global $wp_test_as_next_scheduled;
		return $wp_test_as_next_scheduled;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = [] ) {
		global $wp_test_wp_next_scheduled;
		return $wp_test_wp_next_scheduled;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ) {
		global $wp_test_scheduled_events;
		$wp_test_scheduled_events[] = [ 'timestamp' => $timestamp, 'recurrence' => $recurrence, 'hook' => $hook, 'args' => $args ];
		return true;
	}
}

/*
|--------------------------------------------------------------------------
| Object Cache Stubs (Memcached-like semantics)
|--------------------------------------------------------------------------
*/

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		global $wp_test_ext_object_cache;
		return (bool) $wp_test_ext_object_cache;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $data, string $group = '', int $expire = 0 ): bool {
		global $wp_test_object_cache;
		if ( isset( $wp_test_object_cache[ $group ][ $key ] ) ) {
			return false;
		}
		$wp_test_object_cache[ $group ][ $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, string $group = '', int $expire = 0 ): bool {
		global $wp_test_object_cache;
		$wp_test_object_cache[ $group ][ $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, string $group = '', bool $force = false, &$found = null ) {
		global $wp_test_object_cache;
		if ( isset( $wp_test_object_cache[ $group ][ $key ] ) ) {
			$found = true;
			return $wp_test_object_cache[ $group ][ $key ];
		}
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $key, int $offset = 1, string $group = '' ) {
		global $wp_test_object_cache;
		if ( ! isset( $wp_test_object_cache[ $group ][ $key ] ) || ! is_numeric( $wp_test_object_cache[ $group ][ $key ] ) ) {
			return false;
		}
		$wp_test_object_cache[ $group ][ $key ] += $offset;
		return $wp_test_object_cache[ $group ][ $key ];
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, string $group = '' ): bool {
		global $wp_test_object_cache;
		unset( $wp_test_object_cache[ $group ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
	function wp_cache_get_multiple( array $keys, string $group = '' ): array {
		global $wp_test_object_cache;
		$result = [];
		foreach ( $keys as $key ) {
			$result[ $key ] = $wp_test_object_cache[ $group ][ $key ] ?? false;
		}
		return $result;
	}
}

if ( ! function_exists( 'wp_cache_delete_multiple' ) ) {
	function wp_cache_delete_multiple( array $keys, string $group = '' ): array {
		global $wp_test_object_cache;
		$result = [];
		foreach ( $keys as $key ) {
			unset( $wp_test_object_cache[ $group ][ $key ] );
			$result[ $key ] = true;
		}
		return $result;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
