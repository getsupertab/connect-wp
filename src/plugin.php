<?php
/**
 * Main plugin bootstrap class.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

use Supertab_Connect\Admin\Notices;
use Supertab_Connect\Admin\Onboarding;
use Supertab_Connect\Http\WP_Http_Client;

/**
 * Plugin singleton class.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->load_textdomain();

		$credentials = new Credentials();

		$http_client     = new WP_Http_Client();
		$license_handler = new RSL_License_Handler( $credentials, SUPERTAB_CONNECT_API_BASE_URL, $http_client );
		$license_handler->register();

		if ( is_admin() ) {
			$this->init_admin( $credentials );
		}
	}

	/**
	 * Initialize admin components.
	 *
	 * @param Credentials $credentials Credentials manager.
	 * @return void
	 */
	private function init_admin( Credentials $credentials ): void {
		$onboarding = new Onboarding( $credentials );
		$onboarding->register();

		$notices = new Notices( $credentials );
		$notices->register();
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'supertab-connect',
			false,
			dirname( plugin_basename( SUPERTAB_CONNECT_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
