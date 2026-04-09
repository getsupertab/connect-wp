<?php
/**
 * Admin notices handler.
 *
 * @package Supertab_Connect
 */

declare( strict_types=1 );

namespace Supertab_Connect\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Supertab_Connect\Settings;

/**
 * Displays admin notices for plugin configuration state.
 */
class Notices {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings manager.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
	}

	/**
	 * Show a persistent notice when credentials are not configured.
	 *
	 * @return void
	 */
	public function maybe_show_setup_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->settings->has_credentials() ) {
			return;
		}

		// Don't show on the setup page itself.
		$screen = get_current_screen();
		if ( $screen && 'settings_page_' . Settings_Page::PAGE_SLUG === $screen->id ) {
			return;
		}

		$setup_url = admin_url( 'options-general.php?page=' . Settings_Page::PAGE_SLUG );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Supertab Connect needs to be configured.', 'supertab-connect' ),
			esc_url( $setup_url ),
			esc_html__( 'Set up now', 'supertab-connect' )
		);
	}
}
