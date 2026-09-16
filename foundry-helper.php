<?php
/**
 * Foundry Helper
 *
 * @package           Foundry_Helper
 * @author            Mikey Beck
 * @copyright         2026 Mikey Beck
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Foundry Helper
 * Plugin URI:        https://wpfoundry.app
 * Description:       Pair this site with the WP Foundry desktop app using a shared secret.
 * Version:           4.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mikey Beck
 * Author URI:        https://mikeybeck.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foundry-helper
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WPFOUNDRY_HELPER_LOADED' ) ) {
	return;
}

define( 'WPFOUNDRY_HELPER_LOADED', true );
define( 'WPFOUNDRY_HELPER_VERSION', '4.2.0' );
define( 'WPFOUNDRY_HELPER_FILE', __FILE__ );
define( 'WPFOUNDRY_HELPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPFOUNDRY_HELPER_URL', plugin_dir_url( __FILE__ ) );

define( 'WPFOUNDRY_HELPER_MIN_APP_VERSION', '2.0.0' );
define( 'WPFOUNDRY_HELPER_MAX_APP_VERSION', '2.99.99' );
define( 'WPFOUNDRY_HELPER_PROTOCOL_VERSION', 1 );
define( 'WPFOUNDRY_HELPER_PROTOCOL_MIN', 1 );
define( 'WPFOUNDRY_HELPER_PROTOCOL_MAX', 1 );
define( 'WPFOUNDRY_HELPER_PREVIOUS_SECRET_TTL', 900 );

require_once WPFOUNDRY_HELPER_DIR . 'includes/edition.php';
require_once WPFOUNDRY_HELPER_DIR . 'includes/rest-auth.php';
require_once WPFOUNDRY_HELPER_DIR . 'includes/admin.php';

if ( defined( 'WPFOUNDRY_HELPER_EDITION' ) && 'full' === WPFOUNDRY_HELPER_EDITION ) {
	$wpfoundry_management = WPFOUNDRY_HELPER_DIR . 'includes/rest-management.php';
	$wpfoundry_runner     = WPFOUNDRY_HELPER_DIR . 'includes/command-runner.php';
	if ( file_exists( $wpfoundry_management ) && file_exists( $wpfoundry_runner ) ) {
		require_once $wpfoundry_management;
		require_once $wpfoundry_runner;
	}
}

add_action( 'rest_api_init', 'wpfoundry_register_rest_routes' );
add_action( 'init', 'wpfoundry_register_rewrite' );
add_filter( 'query_vars', 'wpfoundry_register_query_vars' );
add_action( 'template_redirect', 'wpfoundry_handle_custom_endpoint' );
register_activation_hook( WPFOUNDRY_HELPER_FILE, 'wpfoundry_helper_activate' );
add_action( 'admin_menu', 'wpfoundry_register_settings_page' );
add_action( 'admin_enqueue_scripts', 'wpfoundry_admin_enqueue_scripts' );
add_action( 'admin_notices', 'wpfoundry_helper_admin_notices' );
