<?php
/**
 * Plugin Name:       WP Foundry Helper
 * Plugin URI:        https://wpfoundry.app
 * Description:       Full companion plugin for the WP Foundry desktop app (Wails v2). Remote management over HTTPS.
 * Version:           4.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mikey Beck
 * Author URI:        https://mikeybeck.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       foundry-helper
 *
 * 4.0.5 in-app updates look for this filename and read Version via get_plugin_data().
 * Bootstrap remains in foundry-helper.php.
 *
 * @package Foundry_Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/foundry-helper.php';
