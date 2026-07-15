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

// Remove stored settings.
delete_option( 'supertab_connect_merchant_api_key' );
delete_option( 'supertab_connect_website_urn' );
delete_option( 'supertab_connect_bot_protection_enabled' );
delete_option( 'supertab_connect_active_paths' );

// Remove transients.
delete_transient( 'supertab_connect_activating' );
delete_transient( 'supertab_connect_license_xml' );

// Remove the analytics queue table and its schema-version option.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of the plugin's own custom table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}supertab_connect_analytics_queue" );
delete_option( 'supertab_connect_db_version' );
