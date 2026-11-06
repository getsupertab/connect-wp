<?php
/**
 * Environment constants, shared by the plugin and uninstall.php.
 *
 * Each one can be overridden in wp-config.php.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SUPERTAB_CONNECT_ENVIRONMENT' ) ) {
	define( 'SUPERTAB_CONNECT_ENVIRONMENT', 'prod' );
}

if ( ! defined( 'SUPERTAB_CONNECT_API_BASE_URL' ) ) {
	define(
		'SUPERTAB_CONNECT_API_BASE_URL',
		'prod' === SUPERTAB_CONNECT_ENVIRONMENT // @phpstan-ignore identical.alwaysTrue (value is overridable in wp-config.php)
			? 'https://api-connect.supertab.co'
			: 'https://api-connect.sbx.supertab.co'
	);
}

if ( ! defined( 'SUPERTAB_CONNECT_ANALYTICS_BASE_URL' ) ) {
	define(
		'SUPERTAB_CONNECT_ANALYTICS_BASE_URL',
		'prod' === SUPERTAB_CONNECT_ENVIRONMENT // @phpstan-ignore identical.alwaysTrue (value is overridable in wp-config.php)
			? 'https://ingest-connect.supertab.co'
			: 'https://ingest-connect.sbx.supertab.co'
	);
}

// The merchant portal, where Connect website sends the merchant to sign in and approve.
if ( ! defined( 'SUPERTAB_CONNECT_DASHBOARD_URL' ) ) {
	define(
		'SUPERTAB_CONNECT_DASHBOARD_URL',
		'prod' === SUPERTAB_CONNECT_ENVIRONMENT // @phpstan-ignore identical.alwaysTrue (value is overridable in wp-config.php)
			? 'https://merchant-connect.supertab.co'
			: 'https://merchant-connect.sbx.supertab.co'
	);
}
