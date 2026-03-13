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

// Remove stored credentials.
delete_option( 'supertab_connect_merchant_api_key' );
delete_option( 'supertab_connect_website_urn' );

// Remove activation transient if lingering.
delete_transient( 'supertab_connect_activating' );
