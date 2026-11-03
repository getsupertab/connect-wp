<?php
/**
 * Uninstall handler for Supertab Connect.
 *
 * Fires when the plugin is deleted via the WordPress admin.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

// Prevent direct access and verify uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/constants.php';
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Revoke a key the connect flow issued; pasted keys may be used elsewhere. Every failure is ignored.
if ( class_exists( Supertab_Connect\Settings::class ) && class_exists( Supertab_Connect\Connect_Api::class ) ) {
	$supertab_connect_settings = new Supertab_Connect\Settings();
	$supertab_connect_api_key  = $supertab_connect_settings->get_merchant_api_key();
	if ( 'connect' === $supertab_connect_settings->get_connection_method() && '' !== $supertab_connect_api_key ) {
		try {
			( new Supertab_Connect\Connect_Api( SUPERTAB_CONNECT_API_BASE_URL ) )->disconnect( $supertab_connect_api_key, 3 );
		} catch ( \Throwable $e ) {
			// Uninstall must finish even when Supertab can't be reached.
			unset( $e );
		}
	}
}

// Remove stored settings.
delete_option( 'supertab_connect_merchant_api_key' );
delete_option( 'supertab_connect_website_urn' );
delete_option( 'supertab_connect_bot_protection_enabled' );
delete_option( 'supertab_connect_analytics_enabled' );
delete_option( 'supertab_connect_active_paths' );
delete_option( 'supertab_connect_connection' );

// Remove transients.
delete_transient( 'supertab_connect_activating' );
delete_transient( 'supertab_connect_license_xml' );

// Remove the analytics queue table and its schema-version option.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of the plugin's own custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}supertab_connect_analytics_queue" );
delete_option( 'supertab_connect_db_version' );
delete_option( 'supertab_connect_flush_interval' );
