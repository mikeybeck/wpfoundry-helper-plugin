<?php
/**
 * Helper edition for this copy.
 *
 * Working tree and the GitHub zip are "full". deploy.sh overwrites this file
 * in the WordPress.org zip with "directory".
 *
 * @package Foundry_Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPFOUNDRY_HELPER_EDITION' ) ) {
	define( 'WPFOUNDRY_HELPER_EDITION', 'full' );
}
