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

// Cleanup plugin data here.
