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
	 * Nonce action for the setup form.
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
	 * Nonce action for the disconnect form.
	 *
	 * @var string
	 */
	private const DISCONNECT_NONCE_ACTION = 'supertab_connect_disconnect';

	/**
	 * Nonce action for the purge license cache form.
	 *
	 * @var string
	 */
	private const PURGE_CACHE_NONCE_ACTION = 'supertab_connect_purge_cache';

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
		add_action( 'admin_init', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_init', array( $this, 'handle_purge_cache' ) );
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
	 * Handle the disconnect request.
	 *
	 * Does not delete credentials — just redirects back with a flag
	 * so the template shows the credentials form.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		if ( ! isset( $_POST['supertab_connect_disconnect_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value used only for verification.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['supertab_connect_disconnect_nonce'] ), self::DISCONNECT_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'disconnected' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the purge license cache request.
	 *
	 * @return void
	 */
	public function handle_purge_cache(): void {
		if ( ! isset( $_POST['supertab_connect_purge_cache_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce value used only for verification.
		if ( ! wp_verify_nonce( wp_unslash( $_POST['supertab_connect_purge_cache_nonce'] ), self::PURGE_CACHE_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_transient( 'supertab_connect_license_xml' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'purged' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the setup form submission.
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

		$merchant_api_key = isset( $_POST['merchant_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['merchant_api_key'] ) ) : '';
		$website_urn      = isset( $_POST['website_urn'] ) ? sanitize_text_field( wp_unslash( $_POST['website_urn'] ) ) : '';

		if ( '' === $merchant_api_key || '' === $website_urn ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => self::PAGE_SLUG,
						'error' => 'missing_fields',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		$this->credentials->save( $merchant_api_key, $website_urn );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'setup' => 'success',
				),
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
			'nonce_action'             => self::NONCE_ACTION,
			'disconnect_nonce_action'  => self::DISCONNECT_NONCE_ACTION,
			'has_credentials'          => $this->credentials->has_credentials(),
			'disconnected'             => $disconnected,
			'website_urn'              => $this->credentials->get_website_urn(),
			'purge_cache_nonce_action' => self::PURGE_CACHE_NONCE_ACTION,
		);

		include SUPERTAB_CONNECT_PLUGIN_DIR . 'templates/onboarding.php';
	}
}
