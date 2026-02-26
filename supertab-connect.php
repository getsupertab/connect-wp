<?php
/**
 * Plugin Name:       Supertab Connect
 * Plugin URI:        https://supertab.co
 * Description:       Connect your WordPress site to the Supertab platform.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Supertab
 * Author URI:        https://supertab.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       supertab-connect
 * Domain Path:       /languages
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'SUPERTAB_CONNECT_VERSION', '0.1.0' );
define( 'SUPERTAB_CONNECT_PLUGIN_FILE', __FILE__ );
define( 'SUPERTAB_CONNECT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SUPERTAB_CONNECT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoloader.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Bootstrap the plugin.
add_action(
	'plugins_loaded',
	static function (): void {
		Supertab_Connect\Plugin::instance()->init();
	}
);
