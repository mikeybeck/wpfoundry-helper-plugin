<?php
/**
 * Uninstall Foundry Helper.
 *
 * @package Foundry_Helper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpfoundry_shared_secret' );
delete_option( 'wpfoundry_prev_shared_secret' );
delete_option( 'wpfoundry_prev_shared_secret_expires' );

$wpfoundry_upload_dirs = array();

$wpfoundry_uploads = wp_upload_dir();
if ( empty( $wpfoundry_uploads['error'] ) && ! empty( $wpfoundry_uploads['basedir'] ) ) {
	$wpfoundry_upload_dirs[] = trailingslashit( $wpfoundry_uploads['basedir'] ) . 'foundry-helper';
	$wpfoundry_upload_dirs[] = trailingslashit( $wpfoundry_uploads['basedir'] ) . 'wpfoundry-helper';
}

$wpfoundry_upload_dirs[] = trailingslashit( WP_CONTENT_DIR ) . 'uploads/wpfoundry';

$wpfoundry_upload_dirs = array_unique( $wpfoundry_upload_dirs );

foreach ( $wpfoundry_upload_dirs as $wpfoundry_upload_dir ) {
	if ( ! is_dir( $wpfoundry_upload_dir ) ) {
		continue;
	}
	$wpfoundry_upload_files = @scandir( $wpfoundry_upload_dir );
	if ( is_array( $wpfoundry_upload_files ) ) {
		foreach ( $wpfoundry_upload_files as $wpfoundry_upload_file ) {
			if ( '.' === $wpfoundry_upload_file || '..' === $wpfoundry_upload_file ) {
				continue;
			}
			$wpfoundry_upload_path = $wpfoundry_upload_dir . DIRECTORY_SEPARATOR . $wpfoundry_upload_file;
			if ( is_file( $wpfoundry_upload_path ) ) {
				wp_delete_file( $wpfoundry_upload_path );
			}
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the helper's private upload directory on uninstall.
	@rmdir( $wpfoundry_upload_dir );
}
