<?php
/**
 * Settings page template.
 *
 * @package Supertab_Connect
 *
 * @var array $template_data Template variables passed from the controller.
 */

declare( strict_types=1 );

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

$supertab_connect_nonce_action           = $template_data['nonce_action'];
$supertab_connect_has_website_urn        = $template_data['has_website_urn'];
$supertab_connect_has_merchant_api_key   = $template_data['has_merchant_api_key'];
$supertab_connect_disconnected           = $template_data['disconnected'];
$supertab_connect_website_urn            = $template_data['website_urn'];
$supertab_connect_license_url            = $template_data['license_url'];
$supertab_connect_bot_protection_enabled = $template_data['bot_protection_enabled'];
$supertab_connect_active_paths           = $template_data['active_paths'];
$supertab_connect_site_url               = $template_data['site_url'];
$supertab_connect_show_api_key_form      = ! $supertab_connect_has_merchant_api_key || $supertab_connect_disconnected;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_setup_status = isset( $_GET['setup'] ) ? sanitize_text_field( wp_unslash( $_GET['setup'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_purged = isset( $_GET['purged'] ) && '1' === $_GET['purged'];
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Supertab Connect', 'supertab-connect' ); ?></h1>

	<?php if ( 'success' === $supertab_connect_setup_status ) : ?>
		<div class="notice notice-success">
			<p><?php esc_html_e( 'Settings saved successfully.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $supertab_connect_purged ) : ?>
		<div class="notice notice-success">
			<p><?php esc_html_e( 'License cache purged successfully.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'missing_urn' === $supertab_connect_error ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Please enter a Website URN.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'missing_api_key' === $supertab_connect_error ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Please enter a Merchant API Key.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( $supertab_connect_nonce_action, 'supertab_connect_nonce' ); ?>

		<!-- Your RSL License -->
		<h2><?php esc_html_e( 'Your RSL License', 'supertab-connect' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="supertab-website-urn"><?php esc_html_e( 'Website URN', 'supertab-connect' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="supertab-website-urn"
						name="website_urn"
						class="regular-text"
						value="<?php echo esc_attr( $supertab_connect_website_urn ); ?>"
					/>
				</td>
			</tr>
			<?php if ( $supertab_connect_has_website_urn ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'License URL', 'supertab-connect' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $supertab_connect_license_url ); ?>" target="_blank">
							<?php echo esc_html( $supertab_connect_license_url ); ?>
						</a>
					</td>
				</tr>
			<?php endif; ?>
		</table>

		<?php if ( $supertab_connect_has_website_urn ) : ?>
			<?php submit_button( __( 'Purge license.xml from cache', 'supertab-connect' ), 'secondary', 'submit-purge-cache', false ); ?>
		<?php endif; ?>

		<p>&nbsp;</p>

		<?php if ( $supertab_connect_has_website_urn ) : ?>
			<!-- License Verification -->
			<h2><?php esc_html_e( 'License Verification', 'supertab-connect' ); ?></h2>

			<?php if ( $supertab_connect_show_api_key_form ) : ?>

				<?php if ( $supertab_connect_disconnected ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'Current API key remains active until a new one is saved.', 'supertab-connect' ); ?></p>
					</div>
				<?php endif; ?>

				<p><?php esc_html_e( 'Enter your Merchant API Key to enable license verification.', 'supertab-connect' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="supertab-merchant-api-key"><?php esc_html_e( 'Merchant API Key', 'supertab-connect' ); ?></label>
						</th>
						<td>
							<div class="wp-pwd">
								<input
									type="password"
									id="supertab-merchant-api-key"
									name="merchant_api_key"
									class="regular-text"
								/>
								<button type="button"
									class="wp-hide-pw hide-if-no-js"
									aria-label="<?php esc_attr_e( 'Show API key', 'supertab-connect' ); ?>"
									data-show-label="<?php esc_attr_e( 'Show API key', 'supertab-connect' ); ?>"
									data-hide-label="<?php esc_attr_e( 'Hide API key', 'supertab-connect' ); ?>"
								>
									<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
								</button>
							</div>
						</td>
					</tr>
				</table>

			<?php else : ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Merchant API Key', 'supertab-connect' ); ?></th>
						<td><code>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code></td>
					</tr>
				</table>

				<?php submit_button( __( 'Change API key', 'supertab-connect' ), 'secondary', 'submit-disconnect', false ); ?>

				<p>&nbsp;</p>

				<!-- Crawler Authentication Protocol -->
				<h3><?php esc_html_e( 'Crawler Authentication Protocol', 'supertab-connect' ); ?></h3>

				<p><?php esc_html_e( 'When trying to access your content, bots need to present license tokens to the Crawler Authentication Protocol for verification. This allows you to distinguish licensed access from unauthorized scraping, monitor usage against declared terms, and optionally enforce access without blocking compliant bots.', 'supertab-connect' ); ?></p>

				<fieldset>
					<label for="supertab-bot-protection-enabled">
						<input
							type="checkbox"
							id="supertab-bot-protection-enabled"
							name="bot_protection_enabled"
							value="1"
							<?php checked( $supertab_connect_bot_protection_enabled ); ?>
						/>
						<?php esc_html_e( 'Enable CAP', 'supertab-connect' ); ?>
					</label>
				</fieldset>

				<!-- Active Paths -->
				<div id="supertab-active-paths-section"
					<?php echo ! $supertab_connect_bot_protection_enabled ? 'style="display:none;"' : ''; // Only hardcoded strings output. ?>
				>
					<h4><?php esc_html_e( 'Active Paths', 'supertab-connect' ); ?></h4>

					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: wildcard character wrapped in code tags */
								__( 'Define which specific parts of your website Crawler Authentication Protocol should be active on. Paths support %1$s for wildcards.', 'supertab-connect' ),
								'<code>*</code>'
							),
							array( 'code' => array() )
						);
						?>
					</p>

					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: wildcard character wrapped in code tags */
								__( 'Use %1$s for entire site (default).', 'supertab-connect' ),
								'<code>*</code>'
							),
							array( 'code' => array() )
						);
						?>
					</p>

					<div id="supertab-active-paths-list" class="supertab-active-paths-list">
						<?php foreach ( $supertab_connect_active_paths as $supertab_connect_path ) : ?>
							<div class="supertab-active-path-row">
								<span class="supertab-path-prefix"><?php echo esc_html( $supertab_connect_site_url ); ?></span>
								<input
									type="text"
									name="active_paths[]"
									value="<?php echo esc_attr( $supertab_connect_path ); ?>"
									class="regular-text supertab-path-input"
									placeholder="*"
								/>
								<button type="button"
									class="button supertab-remove-path"
									aria-label="<?php esc_attr_e( 'Remove path', 'supertab-connect' ); ?>"
								>
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							</div>
						<?php endforeach; ?>
					</div>

					<button type="button" class="button" id="supertab-add-path">
						<?php esc_html_e( 'Add Path', 'supertab-connect' ); ?>
					</button>
				</div>

			<?php endif; ?>
		<?php endif; ?>

		<?php submit_button( '', 'primary', 'submit-settings' ); ?>

	</form>
</div>
