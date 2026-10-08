<?php
/**
 * Main plugin bootstrap class.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab\Connect\Http\HttpClientInterface;
use Supertab\Connect\Enum\EnforcementMode;
use Supertab\Connect\SupertabConnect;
use Supertab\Connect\Analytics\AnalyticsEvent;
use Supertab\Connect\Analytics\AnalyticsTransportInterface;
use Supertab\Connect\Analytics\CallbackAnalyticsTransport;
use Supertab\Connect\Analytics\DeferredAnalyticsTransport;
use Supertab\Connect\Analytics\HttpAnalyticsTransport;
use Supertab_Connect\Admin\Notices;
use Supertab_Connect\Admin\Settings_Page;
use Supertab_Connect\Utils\WP_Http_Client;
use Supertab_Connect\Utils\WP_Transient_Cache;

/**
 * Plugin singleton class.
 */
class Plugin {

	/**
	 * Timeout in seconds for the analytics POST made on a visitor request when
	 * the queue is off. WordPress's 5s default would hold a PHP worker (and,
	 * without FastCGI, the visitor) for that long whenever the relay is slow.
	 *
	 * @var int
	 */
	private const REQUEST_ANALYTICS_TIMEOUT = 1;

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
		$settings = new Settings();

		$http_client     = new WP_Http_Client();
		$license_handler = new RSL_License_Handler( $settings, SUPERTAB_CONNECT_API_BASE_URL, $http_client );
		$license_handler->register();

		$status_handler = new Status_Handler( $settings, SUPERTAB_CONNECT_API_BASE_URL, $http_client );
		$status_handler->register();

		$robots_txt_handler = new Robots_Txt_Handler( $settings );
		$robots_txt_handler->register();

		$bot_protection_active = $settings->has_merchant_api_key() && $settings->is_bot_protection_enabled();
		$analytics_enabled     = $bot_protection_active && $settings->is_analytics_enabled();

		$dispatcher = null;
		if ( $analytics_enabled && self::should_use_wp_queue() ) {
			$dispatcher = new Analytics_Dispatcher( $settings, $http_client, new Analytics_Queue_Table() );
			$dispatcher->register();
		}

		if ( is_admin() ) {
			$this->init_admin( $settings, $robots_txt_handler );
			return;
		}

		if ( $bot_protection_active && ! defined( 'REST_REQUEST' ) && ! wp_doing_cron() ) {
			$this->init_bot_protection( $settings, $http_client, $dispatcher, $analytics_enabled );
		}
	}

	/**
	 * Initialize admin components.
	 *
	 * @param Settings           $settings           Settings manager.
	 * @param Robots_Txt_Handler $robots_txt_handler Robots.txt handler, for the physical file check.
	 * @return void
	 */
	private function init_admin( Settings $settings, Robots_Txt_Handler $robots_txt_handler ): void {
		$settings_page = new Settings_Page( $settings, $robots_txt_handler );
		$settings_page->register();

		$notices = new Notices( $settings );
		$notices->register();

		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Register privacy policy content for the Supertab Connect service.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			/* translators: 1: link to Supertab Connect privacy policy */
			__( 'This plugin connects to the Supertab Connect API (%1$s) to provide the following functionality:', 'supertab-connect' ),
			'<a href="https://www.supertab.co/legal" target="_blank">supertab.co</a>'
		);

		$content .= '<ul>';
		$content .= '<li>' . __( '<strong>RSL License Serving</strong> — Your Website URN is sent to retrieve the license XML file for your site.', 'supertab-connect' ) . '</li>';
		$content .= '<li>' . __( '<strong>Crawler Authentication Protocol</strong> — When enabled, page URLs and user agent strings from bot requests are sent to verify license tokens and record usage events.', 'supertab-connect' ) . '</li>';
		$content .= '</ul>';

		$content .= __( 'No personal data from your site visitors is collected or transmitted. Only bot request metadata (URL and user agent) is sent when the Crawler Authentication Protocol is enabled by the site administrator.', 'supertab-connect' );

		wp_add_privacy_policy_content(
			'Supertab Connect',
			wp_kses_post( $content )
		);
	}

	/**
	 * Initialize bot protection for front-end requests.
	 *
	 * @param Settings              $settings          Settings manager.
	 * @param HttpClientInterface   $http_client       HTTP client for SDK requests.
	 * @param ?Analytics_Dispatcher $dispatcher        When set, analytics events are queued via this
	 *                                                 dispatcher; when null, the SDK's default transport is used.
	 * @param bool                  $analytics_enabled Whether the merchant opted in to analytics.
	 * @return void
	 */
	private function init_bot_protection( Settings $settings, HttpClientInterface $http_client, ?Analytics_Dispatcher $dispatcher, bool $analytics_enabled ): void {
		$enforcement = self::get_enforcement_mode();

		if ( null !== $dispatcher ) {
			// Queue enabled: hand each event to the dispatcher for off-request delivery.
			$analytics_transport = new CallbackAnalyticsTransport(
				static fn ( AnalyticsEvent $event ) => $dispatcher->enqueue( $event->toArray() ),
				defined( 'WP_DEBUG' ) && WP_DEBUG
			);
		} elseif ( $analytics_enabled ) {
			// Queue disabled: the SDK's default transport shape (deferred on FastCGI,
			// synchronous otherwise), built here so the POST gets its own short timeout.
			$analytics_transport = self::request_analytics_transport( $settings );
		} else {
			// Analytics off: the SDK falls back to its no-op transport.
			$analytics_transport = null;
		}

		$supertab_connect = new SupertabConnect(
			apiKey: $settings->get_merchant_api_key(),
			enforcement: $enforcement,
			httpClient: $http_client,
			baseUrl: SUPERTAB_CONNECT_API_BASE_URL,
			cache: new WP_Transient_Cache(),
			analyticsEnabled: $analytics_enabled,
			analyticsTransport: $analytics_transport,
			analyticsBaseUrl: SUPERTAB_CONNECT_ANALYTICS_BASE_URL,
		);
		$bot_protection   = new Bot_Protection( $supertab_connect, $settings );
		$bot_protection->register();
	}

	/**
	 * Per-request analytics transport used when the queue is off.
	 *
	 * @param Settings $settings Settings manager.
	 * @return AnalyticsTransportInterface
	 */
	private static function request_analytics_transport( Settings $settings ): AnalyticsTransportInterface {
		return new DeferredAnalyticsTransport(
			new HttpAnalyticsTransport(
				$settings->get_merchant_api_key(),
				SUPERTAB_CONNECT_ANALYTICS_BASE_URL,
				new WP_Http_Client( self::REQUEST_ANALYTICS_TIMEOUT ),
				defined( 'WP_DEBUG' ) && WP_DEBUG
			),
			defined( 'WP_DEBUG' ) && WP_DEBUG,
			deferralAvailable: self::should_force_sync_analytics() ? false : null
		);
	}

	/**
	 * Resolve the enforcement mode for bot protection.
	 *
	 * Checks for a SUPERTAB_CONNECT_ENFORCEMENT_MODE constant first,
	 * then applies the 'supertab_connect_enforcement_mode' filter.
	 * Defaults to OBSERVE.
	 *
	 * @return EnforcementMode
	 */
	public static function get_enforcement_mode(): EnforcementMode {
		$default = EnforcementMode::OBSERVE;

		if ( defined( 'SUPERTAB_CONNECT_ENFORCEMENT_MODE' ) ) {
			$mode = self::map_enforcement_mode_value( (string) SUPERTAB_CONNECT_ENFORCEMENT_MODE );
			if ( null !== $mode ) {
				$default = $mode;
			}
		}

		/** This filter is documented in src/plugin.php */
		return apply_filters( 'supertab_connect_enforcement_mode', $default );
	}

	/**
	 * Map a raw enforcement mode value to the SDK enum.
	 *
	 * Accepts the legacy pre-2.0 SDK values 'soft' and 'strict' so sites
	 * configured against plugin 1.2.x keep their behavior after upgrading.
	 *
	 * @param string $value Raw mode value, e.g. from wp-config.php.
	 * @return ?EnforcementMode The matching mode, or null when unrecognized.
	 */
	public static function map_enforcement_mode_value( string $value ): ?EnforcementMode {
		return match ( $value ) {
			'soft'   => EnforcementMode::OBSERVE,
			'strict' => EnforcementMode::ENFORCE,
			default  => EnforcementMode::tryFrom( $value ),
		};
	}

	/**
	 * Whether to route analytics through the WordPress job queue.
	 *
	 * On by default: a visitor request then only writes one row to the buffer
	 * table, and delivery happens in the background job. Opt out by defining
	 * SUPERTAB_CONNECT_USE_WP_QUEUE as false in wp-config.php, which sends one
	 * POST per request instead (deferred past response flush on FastCGI SAPIs,
	 * synchronous otherwise).
	 *
	 * @return bool
	 */
	private static function should_use_wp_queue(): bool {
		if ( ! defined( 'SUPERTAB_CONNECT_USE_WP_QUEUE' ) ) {
			return true;
		}

		return filter_var( constant( 'SUPERTAB_CONNECT_USE_WP_QUEUE' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Mirror of the SDK's SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS escape hatch,
	 * which only applies to the SDK-built transport this plugin replaces.
	 *
	 * @return bool
	 */
	private static function should_force_sync_analytics(): bool {
		return defined( 'SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS' )
			&& filter_var( constant( 'SUPERTAB_CONNECT_FORCE_SYNC_ANALYTICS' ), FILTER_VALIDATE_BOOLEAN );
	}
}
