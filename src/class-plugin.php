<?php
/**
 * Main plugin bootstrap class.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\SupertabConnect;
use Supertab_Connect\Admin\Notices;
use Supertab_Connect\Admin\Settings_Page;
use Supertab_Connect\Utils\WP_Http_Client;
use Supertab_Connect\Utils\WP_Transient_Cache;

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

		$settings = new Settings();

		$http_client     = new WP_Http_Client();
		$license_handler = new RSL_License_Handler( $settings, SUPERTAB_CONNECT_API_BASE_URL, $http_client );
		$license_handler->register();

		if ( is_admin() ) {
			$this->init_admin( $settings );
			return;
		}

		if ( $settings->has_merchant_api_key() && $settings->is_bot_protection_enabled() && ! defined( 'REST_REQUEST' ) ) {
			$this->init_bot_protection( $settings, $http_client );
		}
	}

	/**
	 * Initialize admin components.
	 *
	 * @param Settings $settings Settings manager.
	 * @return void
	 */
	private function init_admin( Settings $settings ): void {
		$settings_page = new Settings_Page( $settings );
		$settings_page->register();

		$notices = new Notices( $settings );
		$notices->register();
	}

	/**
	 * Initialize bot protection for front-end requests.
	 *
	 * @param Settings            $settings    Settings manager.
	 * @param HttpClientInterface $http_client HTTP client for SDK requests.
	 * @return void
	 */
	private function init_bot_protection( Settings $settings, HttpClientInterface $http_client ): void {
		$enforcement      = self::get_enforcement_mode();
		$supertab_connect = new SupertabConnect(
			apiKey: $settings->get_merchant_api_key(),
			enforcement: $enforcement,
			httpClient: $http_client,
			baseUrl: SUPERTAB_CONNECT_API_BASE_URL,
			cache: new WP_Transient_Cache()
		);
		$bot_protection   = new Bot_Protection( $supertab_connect );
		$bot_protection->register();
	}

	/**
	 * Resolve the enforcement mode for bot protection.
	 *
	 * Checks for a SUPERTAB_CONNECT_ENFORCEMENT_MODE constant first,
	 * then applies the 'supertab_connect_enforcement_mode' filter.
	 * Defaults to SOFT.
	 *
	 * @return EnforcementMode
	 */
	private static function get_enforcement_mode(): EnforcementMode {
		$default = EnforcementMode::SOFT;

		if ( defined( 'SUPERTAB_CONNECT_ENFORCEMENT_MODE' ) ) {
			$mode = EnforcementMode::tryFrom( SUPERTAB_CONNECT_ENFORCEMENT_MODE );
			if ( null !== $mode ) {
				$default = $mode;
			}
		}

		/** This filter is documented in src/plugin.php */
		return apply_filters( 'supertab_connect_enforcement_mode', $default );
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
