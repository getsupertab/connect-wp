<?php
/**
 * Onboarding screen controller.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

use Supertab_Connect\Credentials;

/**
 * Handles the onboarding setup page for entering OAuth credentials.
 */
class Onboarding {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'supertab-connect-setup';

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'supertab_connect_setup';

	/**
	 * Credentials instance.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials  Credentials manager.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
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
			__( 'Supertab Connect', 'supertab-connect' ),
			__( 'Supertab Connect', 'supertab-connect' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for the setup page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'supertab-connect-onboarding',
			plugins_url( 'assets/css/onboarding.css', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION
		);

		wp_enqueue_script(
			'supertab-connect-onboarding',
			plugins_url( 'assets/js/onboarding.js', SUPERTAB_CONNECT_PLUGIN_FILE ),
			array(),
			SUPERTAB_CONNECT_VERSION,
			true
		);
	}

	/**
	 * Redirect to setup page after plugin activation.
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

		// Don't redirect if credentials already exist.
		if ( $this->credentials->has_credentials() ) {
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value used only for verification.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['supertab_connect_nonce'] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['submit-purge-cache'] ) ) {
			$this->process_purge_cache();
		} elseif ( isset( $_POST['submit-disconnect'] ) ) {
			$this->process_disconnect();
		} else {
			$this->process_save_settings();
		}
	}

	/**
	 * Save all settings from the form.
	 *
	 * @return void
	 */
	private function process_save_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submission().
		$website_urn = isset( $_POST['website_urn'] ) ? sanitize_text_field( wp_unslash( $_POST['website_urn'] ) ) : '';

		if ( '' === $website_urn ) {
			$this->redirect( array( 'error' => 'missing_urn' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submission().
		$merchant_api_key = isset( $_POST['merchant_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_api_key'] ) ) : '';

		// API key field is only present when entering a new key.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submission().
		if ( isset( $_POST['merchant_api_key'] ) && '' === $merchant_api_key ) {
			$this->redirect( array( 'error' => 'missing_api_key' ) );
		}

		$this->credentials->save_website_urn( $website_urn );

		if ( '' !== $merchant_api_key ) {
			$this->credentials->save_merchant_api_key( $merchant_api_key );
		}

		// Bot protection checkbox is only present when API key is already saved.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submission().
		if ( ! isset( $_POST['merchant_api_key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_form_submission().
			$enabled = isset( $_POST['bot_protection_enabled'] );
			$this->credentials->set_bot_protection_enabled( $enabled );
		}

		$this->redirect( array( 'setup' => 'success' ) );
	}

	/**
	 * Purge the license XML cache.
	 *
	 * @return void
	 */
	private function process_purge_cache(): void {
		delete_transient( 'supertab_connect_license_xml' );

		$this->redirect( array( 'purged' => '1' ) );
	}

	/**
	 * Disconnect the Merchant API Key.
	 *
	 * Does not delete the API key — just redirects back with a flag
	 * so the template shows the API key form.
	 *
	 * @return void
	 */
	private function process_disconnect(): void {
		$this->redirect( array( 'disconnected' => '1' ) );
	}

	/**
	 * Redirect back to the setup page with query args.
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
	 * Render the setup page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query param for display logic.
		$disconnected = isset( $_GET['disconnected'] ) && '1' === $_GET['disconnected'];

		$template_data = array(
			'nonce_action'           => self::NONCE_ACTION,
			'has_website_urn'        => $this->credentials->has_website_urn(),
			'has_merchant_api_key'   => $this->credentials->has_merchant_api_key(),
			'disconnected'           => $disconnected,
			'website_urn'            => $this->credentials->get_website_urn(),
			'license_url'            => home_url( '/license.xml' ),
			'bot_protection_enabled' => $this->credentials->is_bot_protection_enabled(),
		);

		include SUPERTAB_CONNECT_PLUGIN_DIR . 'templates/onboarding.php';
	}
}
