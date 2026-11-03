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
$supertab_connect_changing_key           = $template_data['changing_key'];
$supertab_connect_website_urn            = $template_data['website_urn'];
$supertab_connect_license_url            = $template_data['license_url'];
$supertab_connect_robots_txt_warning     = $template_data['robots_txt_warning'];
$supertab_connect_other_license_urls     = $template_data['other_license_urls'];
$supertab_connect_bot_protection_enabled = $template_data['bot_protection_enabled'];
$supertab_connect_analytics_enabled      = $template_data['analytics_enabled'];
$supertab_connect_active_paths           = $template_data['active_paths'];
$supertab_connect_site_url               = $template_data['site_url'];
$supertab_connect_resource_links         = $template_data['resource_links'];
$supertab_connect_connection_method      = $template_data['connection_method'];
$supertab_connect_website_name           = $template_data['website_name'];
$supertab_connect_connected_host         = $template_data['connected_host'];
$supertab_connect_home_origin            = $template_data['home_origin'];
$supertab_connect_connected_origin       = $template_data['connected_origin'];
$supertab_connect_address_changed        = $template_data['address_changed'];
$supertab_connect_result_notice          = $template_data['result_notice'];
$supertab_connect_disconnect_unreachable = $template_data['disconnect_unreachable'];
$supertab_connect_admin_post_url         = $template_data['admin_post_url'];
$supertab_connect_start_action           = $template_data['start_action'];
$supertab_connect_disconnect_action      = $template_data['disconnect_action'];
$supertab_connect_show_api_key_form      = ! $supertab_connect_has_merchant_api_key || $supertab_connect_changing_key;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_setup_status = isset( $_GET['setup'] ) ? sanitize_text_field( wp_unslash( $_GET['setup'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reading query params for display logic.
$supertab_connect_purged = isset( $_GET['purged'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['purged'] ) );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Supertab Connect', 'supertab-connect' ); ?></h1>

	<p class="description"><?php esc_html_e( 'Publish a machine-readable license file on your website, manage how partners access your content and track licensed usage.', 'supertab-connect' ); ?></p>

	<?php if ( null !== $supertab_connect_result_notice ) : ?>
		<div class="notice <?php echo esc_attr( $supertab_connect_result_notice['class'] ); ?>">
			<p><?php echo esc_html( $supertab_connect_result_notice['text'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $supertab_connect_disconnect_unreachable ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( "Couldn't reach Supertab. Disconnect anyway?", 'supertab-connect' ); ?></p>
			<form method="post" action="<?php echo esc_url( $supertab_connect_admin_post_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $supertab_connect_disconnect_action ); ?>" />
				<input type="hidden" name="force" value="1" />
				<?php wp_nonce_field( $supertab_connect_disconnect_action ); ?>
				<p><?php submit_button( __( 'Disconnect anyway', 'supertab-connect' ), 'secondary', 'submit-disconnect-anyway', false ); ?></p>
			</form>
		</div>
	<?php endif; ?>

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

	<div class="supertab-settings-layout">
		<div class="supertab-settings-main">

	<?php if ( ! $supertab_connect_has_website_urn ) : ?>

		<!-- Not connected -->
		<h2><?php esc_html_e( 'Connect your website', 'supertab-connect' ); ?></h2>

		<p><?php esc_html_e( 'Connect this site to Supertab to publish your license and manage how partners access your content.', 'supertab-connect' ); ?></p>

		<form method="post" action="<?php echo esc_url( $supertab_connect_admin_post_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $supertab_connect_start_action ); ?>" />
			<?php wp_nonce_field( $supertab_connect_start_action ); ?>
			<?php submit_button( __( 'Connect website', 'supertab-connect' ), 'primary', 'submit-connect', false ); ?>
		</form>

		<p class="description"><?php esc_html_e( "You'll create a Supertab account or sign in, then come straight back.", 'supertab-connect' ); ?></p>

		<details class="supertab-manual-credentials"<?php echo '' !== $supertab_connect_error ? ' open' : ''; // Only hardcoded strings output. ?>>
			<summary><?php esc_html_e( 'Enter credentials manually', 'supertab-connect' ); ?></summary>

			<form method="post" action="">
				<?php wp_nonce_field( $supertab_connect_nonce_action, 'supertab_connect_nonce' ); ?>

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
								value=""
							/>
						</td>
					</tr>
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
							<p class="description"><?php esc_html_e( 'Optional. Needed for license verification.', 'supertab-connect' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save credentials', 'supertab-connect' ), 'secondary', 'submit-settings', false ); ?>
			</form>
		</details>

	<?php else : ?>

		<!-- Connection -->
		<h2><?php esc_html_e( 'Connection', 'supertab-connect' ); ?></h2>

		<p class="supertab-connection">
			<?php if ( 'connect' === $supertab_connect_connection_method ) : ?>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: website name in Supertab Connect, 2: website host */
						__( 'Connected to %1$s (%2$s)', 'supertab-connect' ),
						'<strong>' . esc_html( $supertab_connect_website_name ) . '</strong>',
						esc_html( $supertab_connect_connected_host )
					),
					array( 'strong' => array() )
				);
				?>
			<?php else : ?>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Website URN */
						__( 'Connected with Website URN %s', 'supertab-connect' ),
						'<code>' . esc_html( $supertab_connect_website_urn ) . '</code>'
					),
					array( 'code' => array() )
				);
				?>
			<?php endif; ?>
		</p>

		<?php if ( $supertab_connect_address_changed ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: the site's current address, 2: the address it was connected with */
							__( "Your site's address is now %1\$s, but it's connected to Supertab as %2\$s. Reconnect to update it.", 'supertab-connect' ),
							$supertab_connect_home_origin,
							$supertab_connect_connected_origin
						)
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $supertab_connect_admin_post_url ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $supertab_connect_start_action ); ?>" />
					<?php wp_nonce_field( $supertab_connect_start_action ); ?>
					<p><?php submit_button( __( 'Reconnect', 'supertab-connect' ), 'primary', 'submit-reconnect', false ); ?></p>
				</form>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $supertab_connect_admin_post_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $supertab_connect_disconnect_action ); ?>" />
			<?php wp_nonce_field( $supertab_connect_disconnect_action ); ?>
			<?php submit_button( __( 'Disconnect', 'supertab-connect' ), 'secondary', 'submit-disconnect', false ); ?>
		</form>

		<p>&nbsp;</p>

	<form method="post" action="">
		<?php wp_nonce_field( $supertab_connect_nonce_action, 'supertab_connect_nonce' ); ?>

		<!-- Your RSL License -->
		<h2><?php esc_html_e( 'Your RSL License', 'supertab-connect' ); ?></h2>

		<p class="description"><?php esc_html_e( 'Whenever you update your license in Supertab Connect, the license.xml file on your website is updated automatically. Changes may take some time to appear due to caching. You can purge the cache to see them immediately.', 'supertab-connect' ); ?></p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Website URN', 'supertab-connect' ); ?></th>
				<td><code><?php echo esc_html( $supertab_connect_website_urn ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'License URL', 'supertab-connect' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $supertab_connect_license_url ); ?>" target="_blank">
						<?php echo esc_html( $supertab_connect_license_url ); ?>
					</a>
				</td>
			</tr>
		</table>
		<?php if ( $supertab_connect_robots_txt_warning ) : ?>
			<div id="supertab-robots-txt-notice" class="notice notice-warning inline">
				<p><strong><?php esc_html_e( "We couldn't update your robots.txt automatically.", 'supertab-connect' ); ?></strong></p>
				<p><?php esc_html_e( "Your site uses a robots.txt file stored on the server, which WordPress can't change. Add this line to the end of that file so crawlers can find your license:", 'supertab-connect' ); ?></p>
				<p><code>License: <?php echo esc_html( $supertab_connect_license_url ); ?></code></p>
				<p><?php esc_html_e( "You can edit the file with your hosting provider's file manager or over SFTP. If you're not sure how, ask your hosting provider or web developer.", 'supertab-connect' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $supertab_connect_other_license_urls ) ) : ?>
			<div id="supertab-robots-txt-other-license-notice" class="notice notice-info inline">
				<p><strong><?php esc_html_e( 'Your robots.txt points to a different license.', 'supertab-connect' ); ?></strong></p>
				<p><?php esc_html_e( 'The robots.txt file stored on your server declares:', 'supertab-connect' ); ?></p>
				<?php foreach ( $supertab_connect_other_license_urls as $supertab_connect_other_license_url ) : ?>
					<p><code>License: <?php echo esc_html( $supertab_connect_other_license_url ); ?></code></p>
				<?php endforeach; ?>
				<p><?php esc_html_e( "If that's not intended, replace it with this line so crawlers find the license served by this plugin:", 'supertab-connect' ); ?></p>
				<p><code>License: <?php echo esc_html( $supertab_connect_license_url ); ?></code></p>
			</div>
		<?php endif; ?>


		<?php submit_button( __( 'Purge license.xml from cache', 'supertab-connect' ), 'secondary', 'submit-purge-cache', false ); ?>

		<p>&nbsp;</p>

		<!-- License Verification -->
		<h2><?php esc_html_e( 'License Verification', 'supertab-connect' ); ?></h2>

		<p class="description"><?php esc_html_e( 'Generate an API key in Supertab Connect to verify requests and issue licenses to your customers.', 'supertab-connect' ); ?></p>

		<?php if ( $supertab_connect_show_api_key_form ) : ?>

			<?php if ( $supertab_connect_changing_key ) : ?>
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

			<?php if ( 'manual' === $supertab_connect_connection_method ) : ?>
				<?php submit_button( __( 'Change API key', 'supertab-connect' ), 'secondary', 'submit-change-api-key', false ); ?>
			<?php endif; ?>

			<p>&nbsp;</p>

				<!-- Crawler Authentication Protocol -->
			<h3><?php esc_html_e( 'Crawler Authentication Protocol', 'supertab-connect' ); ?></h3>

			<p class="description"><?php esc_html_e( 'When bots owned by connected customers want to access your content, they can present a license to verify their access. This allows you to distinguish licensed usage from other traffic and track activity against your agreements.', 'supertab-connect' ); ?></p>
			<p class="description"><?php esc_html_e( 'CAP applies to requests from connected customers. Other traffic (e.g. search engines) is not blocked.', 'supertab-connect' ); ?></p>

			<br />

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
				<br />

				<!-- Analytics -->
				<fieldset>
					<label for="supertab-analytics-enabled">
						<input
							type="checkbox"
							id="supertab-analytics-enabled"
							name="analytics_enabled"
							value="1"
							<?php checked( $supertab_connect_analytics_enabled ); ?>
						/>
						<?php esc_html_e( 'Enable agent & bot classification', 'supertab-connect' ); ?>
					</label>
				</fieldset>

				<p class="description">
					<?php esc_html_e( 'Share agent & bot traffic analytics with Supertab to get insights into machine content consumption. Disabled by default.', 'supertab-connect' ); ?>
				</p>

				<h4><?php esc_html_e( 'Active Paths', 'supertab-connect' ); ?></h4>

				<p class="description">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: 1: wildcard character wrapped in code tags */
							__( 'Choose which parts of your site require license verification for connected partners. Paths support wildcards. Use %1$s to apply this to your entire site (default).', 'supertab-connect' ),
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

		<?php submit_button( '', 'primary', 'submit-settings' ); ?>

	</form>

	<?php endif; ?>

		</div><!-- .supertab-settings-main -->

		<aside class="supertab-settings-sidebar">
			<div class="postbox">
				<h2 class="hndle"><?php esc_html_e( 'Resources', 'supertab-connect' ); ?></h2>
				<div class="inside">
					<ul class="supertab-resource-links">
						<?php foreach ( $supertab_connect_resource_links as $supertab_connect_link ) : ?>
							<li>
								<span class="dashicons <?php echo esc_attr( $supertab_connect_link['icon'] ); ?>" aria-hidden="true"></span>
								<a href="<?php echo esc_url( $supertab_connect_link['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( $supertab_connect_link['label'] ); ?>
									<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'supertab-connect' ); ?></span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</aside>

	</div><!-- .supertab-settings-layout -->
</div>
