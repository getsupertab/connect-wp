<?php
/**
 * PHPUnit bootstrap for integration tests.
 *
 * Loads the WordPress test framework (installed by bin/install-wp-tests.sh)
 * and registers the plugin via the muplugins_loaded hook so it is active
 * inside the test WP install.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find {$_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 2 ) . '/supertab-connect.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
