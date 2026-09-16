<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation: create a shared secret and flush rewrite rules.
 */
function wpfoundry_helper_activate() {
	wpfoundry_get_shared_secret( true );
	wpfoundry_register_rewrite();
	flush_rewrite_rules();

	if ( ! wpfoundry_is_directory_edition() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$directory_plugin = 'foundry-helper/foundry-helper.php';
		if ( plugin_basename( WPFOUNDRY_HELPER_FILE ) !== $directory_plugin && is_plugin_active( $directory_plugin ) ) {
			deactivate_plugins( $directory_plugin, true );
		}
	}
}

/**
 * Admin notices for dual install / pairing-stub guidance.
 */
function wpfoundry_helper_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_foundry-helper' ), true ) ) {
		return;
	}

	if ( wpfoundry_is_directory_edition() ) {
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Foundry Helper on WordPress.org is a pairing plugin. Install the full WP Foundry Helper from wpfoundry.app to manage this site from the desktop app.', 'foundry-helper' );
		echo ' <a href="https://wpfoundry.app" target="_blank" rel="noopener noreferrer">wpfoundry.app</a>';
		echo '</p></div>';
		return;
	}

	if ( is_plugin_active( 'foundry-helper/foundry-helper.php' ) && defined( 'WPFOUNDRY_HELPER_FILE' ) && false === strpos( WPFOUNDRY_HELPER_FILE, '/foundry-helper/foundry-helper.php' ) ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'The WordPress.org Foundry Helper is still active. Deactivate it to avoid duplicate REST routes. Pairing credentials are shared, so you do not need to re-copy the secret.', 'foundry-helper' );
		echo '</p></div>';
	}
}

/**
 * Settings screen under Settings → Foundry Helper.
 */
function wpfoundry_register_settings_page() {
	add_options_page(
		__( 'Foundry Helper', 'foundry-helper' ),
		__( 'Foundry Helper', 'foundry-helper' ),
		'manage_options',
		'foundry-helper',
		'wpfoundry_render_settings_page'
	);
}

/**
 * Enqueue the settings-page script (show/hide/copy shared secret).
 *
 * @param string $hook Current admin page hook.
 */
function wpfoundry_admin_enqueue_scripts( $hook ) {
	if ( 'settings_page_foundry-helper' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'foundry-helper-admin',
		WPFOUNDRY_HELPER_URL . 'assets/js/admin.js',
		array(),
		WPFOUNDRY_HELPER_VERSION,
		true
	);
}

/**
 * Render the pairing settings page.
 */
function wpfoundry_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = '';
	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	if ( 'POST' === $request_method && isset( $_POST['wpfoundry_regenerate_secret'] ) ) {
		check_admin_referer( 'wpfoundry_regenerate_secret' );
		wpfoundry_get_shared_secret( true );
		$message = __( 'Shared secret regenerated. Update your WP Foundry site settings.', 'foundry-helper' );
	}

	$secret = wpfoundry_get_shared_secret( false );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Foundry Helper', 'foundry-helper' ); ?></h1>
		<p><?php echo esc_html__( 'Use the shared secret below to pair this site with the WP Foundry desktop app.', 'foundry-helper' ); ?></p>
		<?php if ( wpfoundry_is_directory_edition() ) : ?>
			<p><?php echo esc_html__( 'This WordPress.org plugin only handles pairing. Remote management (plugins, themes, backups, WP-CLI) requires the full helper from wpfoundry.app or GitHub.', 'foundry-helper' ); ?></p>
		<?php endif; ?>
		<?php if ( $message ) : ?>
			<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Shared secret', 'foundry-helper' ); ?></th>
				<td>
					<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<input
							type="password"
							id="wpfoundry-shared-secret"
							class="regular-text code"
							readonly
							autocomplete="off"
							spellcheck="false"
							value="<?php echo esc_attr( $secret ); ?>"
							aria-describedby="wpfoundry-shared-secret-desc"
						/>
						<button
							type="button"
							class="button button-secondary"
							id="wpfoundry-toggle-secret"
							data-show="<?php echo esc_attr__( 'Show', 'foundry-helper' ); ?>"
							data-hide="<?php echo esc_attr__( 'Hide', 'foundry-helper' ); ?>"
						><?php echo esc_html__( 'Show', 'foundry-helper' ); ?></button>
						<button
							type="button"
							class="button button-secondary"
							id="wpfoundry-copy-secret"
							data-copied="<?php echo esc_attr__( 'Copied', 'foundry-helper' ); ?>"
							data-failed="<?php echo esc_attr__( 'Copy failed', 'foundry-helper' ); ?>"
						><?php echo esc_html__( 'Copy', 'foundry-helper' ); ?></button>
					</div>
					<p id="wpfoundry-shared-secret-desc" class="description">
						<?php echo esc_html__( 'Keep this secret private. It is hidden by default to prevent screenshots and screen-share leaks. Regenerating will revoke existing access.', 'foundry-helper' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'wpfoundry_regenerate_secret' ); ?>
			<p>
				<button type="submit" name="wpfoundry_regenerate_secret" class="button button-secondary">
					<?php echo esc_html__( 'Regenerate secret', 'foundry-helper' ); ?>
				</button>
			</p>
		</form>
	</div>
	<?php
}
