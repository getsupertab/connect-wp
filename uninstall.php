<?php
/**
 * Uninstall handler for Supertab Connect.
 *
 * Fires when the plugin is deleted via the WordPress admin.
 *
 * @package Supertab_Connect
 */

// Prevent direct access and verify uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove stored credentials and settings.
delete_option( 'supertab_connect_merchant_api_key' );
delete_option( 'supertab_connect_website_urn' );
delete_option( 'supertab_connect_bot_protection_enabled' );

// Remove transients.
delete_transient( 'supertab_connect_activating' );
delete_transient( 'supertab_connect_license_xml' );
