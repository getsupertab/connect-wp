<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines runtime constants and optional third-party functions that PHPStan
 * cannot discover from static analysis alone (no stub package ships them).
 *
 * @package Supertab_Connect
 */

define( 'SUPERTAB_CONNECT_VERSION', '0.1.0' );
define( 'SUPERTAB_CONNECT_PLUGIN_FILE', dirname( __DIR__ ) . '/supertab-connect.php' );
define( 'SUPERTAB_CONNECT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'SUPERTAB_CONNECT_PLUGIN_URL', 'https://example.com/wp-content/plugins/supertab-connect/' );
define( 'SUPERTAB_CONNECT_ENVIRONMENT', 'sbx' );
define( 'SUPERTAB_CONNECT_API_BASE_URL', 'https://api-connect.sbx.supertab.co' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Signature-only stub of Action Scheduler's async enqueue function.
	 *
	 * Real implementation is provided at runtime by the Action Scheduler
	 * library (bundled with WooCommerce or as a standalone plugin) when
	 * active. Declared here purely so PHPStan can type-check the
	 * `call_user_func( 'as_enqueue_async_action', ... )` call site — without
	 * it, PHPStan cannot verify the string is a valid callable and flags an
	 * `argument.type` error even though the call is correctly guarded by
	 * `function_exists()` at runtime.
	 *
	 * @param string             $hook  Action hook to trigger.
	 * @param array<int, mixed>  $args  Arguments to pass to the hook.
	 * @param string             $group Action group.
	 * @return int Action ID.
	 */
	function as_enqueue_async_action( string $hook, array $args = array(), string $group = '' ): int {
		return 0;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Signature-only stub of Action Scheduler's unschedule-all function.
	 *
	 * Real implementation is provided at runtime by the Action Scheduler
	 * library (bundled with WooCommerce or as a standalone plugin) when
	 * active. Declared here purely so PHPStan can type-check the
	 * `call_user_func( 'as_unschedule_all_actions', ... )` call site —
	 * without it, PHPStan cannot verify the string is a valid callable and
	 * flags an `argument.type` error even though the call is correctly
	 * guarded by `function_exists()` at runtime.
	 *
	 * @param string             $hook  Action hook to unschedule.
	 * @param array<int, mixed>  $args  Arguments matching the scheduled action.
	 * @param string             $group Action group.
	 * @return void
	 */
	function as_unschedule_all_actions( string $hook = '', array $args = array(), string $group = '' ): void {
	}
}
