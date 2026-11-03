<?php
/**
 * Settings page controller.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab_Connect\Robots_Txt_Handler;
use Supertab_Connect\RSL_License_Handler;
use Supertab_Connect\Settings;
use Supertab_Connect\Utils\Site_Address;

/**
 * Handles the plugin settings page.
 */
class Settings_Page {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'supertab-connect-settings';

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'supertab_connect_settings';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Robots.txt handler.
	 *
	 * @var Robots_Txt_Handler
	 */
	private Robots_Txt_Handler $robots_txt_handler;

	/**
	 * Constructor.
	 *
	 * @param Settings           $settings           Settings manager.
	 * @param Robots_Txt_Handler $robots_txt_handler Robots.txt handler.
	 */
	public function __construct( Settings $settings, Robots_Txt_Handler $robots_txt_handler ) {
		$this->settings           = $settings;
		$this->robots_txt_handler = $robots_txt_handler;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_activation_redirect' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
	}

	/**
	 * Register the admin page under Settings.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			'options-general.php',
			'Supertab Connect',
			'Supertab Connect',
			Access::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for the settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'supertab-connect-settings',
			plugins_url( 'assets/css/settings.css', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION
		);

		wp_enqueue_script(
			'supertab-connect-settings',
			plugins_url( 'assets/js/settings.js', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION,
			true
		);

		wp_localize_script(
			'supertab-connect-settings',
			'supertabConnectSettings',
			array(
				'i18n' => array(
					'removePath' => __( 'Remove path', 'supertab-connect' ),
				),
			)
		);
	}

	/**
	 * Redirect to settings page after plugin activation.
	 *
	 * @return void
	 */
	public function handle_activation_redirect(): void {
		if ( ! get_transient( 'supertab_connect_activating' ) ) {
			return;
		}

		delete_transient( 'supertab_connect_activating' );

		// Don't redirect on multisite bulk activation or WP-CLI.
		if ( wp_doing_ajax() || is_network_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Don't redirect if settings already configured.
		if ( $this->settings->has_credentials() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Handle the settings form submission.
	 *
	 * Dispatches to the appropriate action based on which submit button was pressed.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		if ( ! isset( $_POST['supertab_connect_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['supertab_connect_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( Access::capability() ) ) {
			return;
		}

		if ( isset( $_POST['submit-purge-cache'] ) ) {
			$this->process_purge_cache();
		} elseif ( isset( $_POST['submit-change-api-key'] ) ) {
			$this->process_change_api_key();
		} else {
			$form_data = array(
				// Only the manual credentials form has a URN field; the connected view keeps the stored one.
				'website_urn'            => isset( $_POST['website_urn'] ) ? sanitize_text_field( wp_unslash( $_POST['website_urn'] ) ) : null,
				'merchant_api_key'       => isset( $_POST['merchant_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_api_key'] ) ) : null,
				'bot_protection_enabled' => isset( $_POST['bot_protection_enabled'] ),
				'analytics_enabled'      => isset( $_POST['analytics_enabled'] ),
				'active_paths'           => isset( $_POST['active_paths'] ) && is_array( $_POST['active_paths'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['active_paths'] ) )
					: array(),
			);
			$this->process_save_settings( $form_data );
		}
	}

	/**
	 * Save all settings from the form.
	 *
	 * @param array<string, mixed> $form_data Sanitized form data from handle_form_submission().
	 * @return void
	 */
	private function process_save_settings( array $form_data ): void {
		$website_urn = $form_data['website_urn'];

		if ( '' === $website_urn ) {
			$this->redirect( array( 'error' => 'missing_urn' ) );
		}

		$merchant_api_key = $form_data['merchant_api_key'];

		if ( is_string( $website_urn ) ) {
			$this->settings->save_website_urn( $website_urn );
			// Typed in by hand: the key may be used elsewhere, so Disconnect never revokes it.
			$this->settings->save_connection( 'manual', '', home_url() );
		}

		// API key field is only present when entering a new key; save only if non-empty.
		if ( is_string( $merchant_api_key ) && '' !== $merchant_api_key ) {
			$this->settings->save_merchant_api_key( $merchant_api_key );
			// A key typed in by hand may be used elsewhere, so Disconnect must never revoke it.
			$this->settings->save_connection( 'manual', '', home_url() );
		} elseif ( '' === $merchant_api_key ) {
			// API key field was shown but submitted empty — disable bot protection.
			$this->settings->set_bot_protection_enabled( false );
			$this->settings->set_analytics_enabled( false );
		}

		// Bot protection settings are only present when API key is already saved.
		if ( null === $merchant_api_key ) {
			$this->settings->set_bot_protection_enabled( $form_data['bot_protection_enabled'] );
			$this->settings->set_analytics_enabled( $form_data['analytics_enabled'] );

			$raw_paths = $form_data['active_paths'];

			$paths = array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( string $path ): string {
								return ltrim( $path, '/' );
							},
							$raw_paths
						),
						static function ( string $path ): bool {
							return '' !== $path;
						}
					)
				)
			);

			if ( empty( $paths ) ) {
				$paths = array( '*' );
			}

			$this->settings->set_active_paths( $paths );
		}

		$this->redirect( array( 'setup' => 'success' ) );
	}

	/**
	 * Purge the license XML cache.
	 *
	 * @return void
	 */
	private function process_purge_cache(): void {
		delete_transient( RSL_License_Handler::CACHE_TRANSIENT_KEY );

		$this->redirect( array( 'purged' => '1' ) );
	}

	/**
	 * Show the API key form again so a manually entered key can be replaced.
	 *
	 * Does not delete the API key; it stays active until a new one is saved.
	 *
	 * @return void
	 */
	private function process_change_api_key(): void {
		$this->redirect( array( 'change_key' => '1' ) );
	}

	/**
	 * Redirect back to the settings page with query args.
	 *
	 * @param array<string, string> $args Query arguments to append.
	 * @return void
	 */
	private function redirect( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * The notice for a connect, cancel, disconnect or error result in the query string.
	 *
	 * @return array{class: string, text: string}|null
	 */
	private function result_notice(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
		$result = isset( $_GET['connect'] ) ? sanitize_text_field( wp_unslash( $_GET['connect'] ) ) : '';
		$error  = isset( $_GET['connect_error'] ) ? sanitize_text_field( wp_unslash( $_GET['connect_error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$expired = __( 'This connection link expired or was already used. Click Connect website to try again.', 'supertab-connect' );
		$errors  = array(
			'invalid_code'        => $expired,
			'invalid_state'       => $expired,
			'wrong_user'          => __( 'This connection was started by a different WordPress user. Click Connect website to try again.', 'supertab-connect' ),
			'site_mismatch'       => __( 'Supertab approved this connection for a different site address. Click Connect website to try again.', 'supertab-connect' ),
			'failed'              => __( "Supertab couldn't connect this site. Click Connect website to try again, or enter your credentials manually.", 'supertab-connect' ),
			'unreachable'         => __( "Couldn't reach Supertab. Check that your site can make outgoing requests and try again.", 'supertab-connect' ),
			'admin_host_mismatch' => sprintf(
				/* translators: %s: the site's host, e.g. example.com */
				__( 'Connect website needs your WordPress admin on the same address as your site (%s). Enter your credentials manually instead.', 'supertab-connect' ),
				Site_Address::host( home_url() )
			),
			'insecure_admin'      => __( 'Connect website needs your WordPress admin on HTTPS. Enter your credentials manually instead.', 'supertab-connect' ),
		);
		if ( isset( $errors[ $error ] ) ) {
			return array(
				'class' => 'notice-error',
				'text'  => $errors[ $error ],
			);
		}

		$results = array(
			'connected'    => array( 'notice-success', __( 'Connected', 'supertab-connect' ) ),
			'disconnected' => array( 'notice-success', __( 'Disconnected from Supertab.', 'supertab-connect' ) ),
			'cancelled'    => array( 'notice-info', __( 'Connection cancelled', 'supertab-connect' ) ),
		);
		if ( isset( $results[ $result ] ) ) {
			return array(
				'class' => $results[ $result ][0],
				'text'  => $results[ $result ][1],
			);
		}

		return null;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( Access::capability() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
		$changing_key           = isset( $_GET['change_key'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['change_key'] ) );
		$disconnect_unreachable = isset( $_GET['disconnect'] ) && 'unreachable' === sanitize_text_field( wp_unslash( $_GET['disconnect'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$connection     = $this->settings->get_connection();
		$connected_base = null !== $connection ? $connection['base_url'] : '';
		$method         = $this->settings->get_connection_method();

		$template_data = array(
			'nonce_action'           => self::NONCE_ACTION,
			'has_website_urn'        => $this->settings->has_website_urn(),
			'has_merchant_api_key'   => $this->settings->has_merchant_api_key(),
			'changing_key'           => $changing_key,
			'connection_method'      => $method,
			'website_name'           => null !== $connection ? $connection['website_name'] : '',
			'connected_host'         => Site_Address::host( $connected_base ),
			'home_origin'            => Site_Address::origin( home_url() ),
			'connected_origin'       => Site_Address::origin( $connected_base ),
			// The API stores the origin only, so a subdirectory install is not a move.
			'address_changed'        => 'connect' === $method && '' !== $connected_base && Site_Address::origin( home_url() ) !== Site_Address::origin( $connected_base ),
			'result_notice'          => $this->result_notice(),
			'disconnect_unreachable' => $disconnect_unreachable,
			'admin_post_url'         => admin_url( 'admin-post.php' ),
			'start_action'           => Connect_Flow::START_ACTION,
			'disconnect_action'      => Connect_Flow::DISCONNECT_ACTION,
			'website_urn'            => $this->settings->get_website_urn(),
			'license_url'            => home_url( '/license.xml' ),
			'robots_txt_warning'     => $this->robots_txt_handler->needs_manual_license_directive(),
			'other_license_urls'     => $this->robots_txt_handler->get_other_license_urls(),
			'bot_protection_enabled' => $this->settings->is_bot_protection_enabled(),
			'analytics_enabled'      => $this->settings->is_analytics_enabled(),
			'active_paths'           => $this->settings->get_active_paths(),
			'site_url'               => home_url( '/' ),
			'resource_links'         => array(
				array(
					'url'   => 'https://supertab-connect.mintlify.app/introduction/overview',
					'label' => __( 'Documentation', 'supertab-connect' ),
					'icon'  => 'dashicons-book-alt',
				),
				array(
					'url'   => 'https://merchant-connect.supertab.co',
					'label' => __( 'Supertab Connect Dashboard', 'supertab-connect' ),
					'icon'  => 'dashicons-external',
				),
			),
		);

		include SUPERTAB_CONNECT_PLUGIN_DIR . 'templates/settings.php';
	}
}
