<?php
/**
 * Legacy loader for sites that still bootstrap this filename.
 * Not a plugin entry point — Foundry Helper is registered in foundry-helper.php.
 *
 * @package Foundry_Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/foundry-helper.php';
