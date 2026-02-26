<?php
/**
 * Onboarding setup page template.
 *
 * @package Supertab_Connect
 *
 * @var array $template_data Template variables passed from the controller.
 */

declare( strict_types=1 );

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

$supertab_connect_nonce_action    = $template_data['nonce_action'];
$supertab_connect_has_credentials = $template_data['has_credentials'];
$supertab_connect_website_urn     = $template_data['website_urn'];

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_setup_status = isset( $_GET['setup'] ) ? sanitize_text_field( wp_unslash( $_GET['setup'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_error_message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';
$supertab_connect_error_message = rawurldecode( $supertab_connect_error_message );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Supertab Connect Setup', 'supertab-connect' ); ?></h1>

	<?php if ( 'success' === $supertab_connect_setup_status ) : ?>
		<div class="notice notice-success">
			<p><?php esc_html_e( 'Credentials saved successfully.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'missing_fields' === $supertab_connect_error ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Please fill in both the Merchant API Key and Website URN.', 'supertab-connect' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'verification_failed' === $supertab_connect_error ) : ?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'Credential verification failed.', 'supertab-connect' ); ?>
				<?php if ( '' !== $supertab_connect_error_message ) : ?>
					<?php echo esc_html( $supertab_connect_error_message ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $supertab_connect_has_credentials ) : ?>
		<p><?php esc_html_e( 'Supertab Connect is configured and ready to use.', 'supertab-connect' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Merchant API Key', 'supertab-connect' ); ?></th>
				<td><code>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Website URN', 'supertab-connect' ); ?></th>
				<td><code><?php echo esc_html( $supertab_connect_website_urn ); ?></code></td>
			</tr>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'Enter your Supertab credentials to connect your site.', 'supertab-connect' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( $supertab_connect_nonce_action, 'supertab_connect_nonce' ); ?>

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
								required
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
							required
						/>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save', 'supertab-connect' ) ); ?>
		</form>
	<?php endif; ?>
</div>
