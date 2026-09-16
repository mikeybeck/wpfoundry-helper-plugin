<?php
/**
 * Full-edition REST routes: WP-CLI, backups download, zip upload/install.
 * Not included in the WordPress.org pairing-stub zip.
 *
 * @package Foundry_Helper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register HMAC-authenticated management REST routes.
 */
function wpfoundry_register_management_rest_routes() {
	register_rest_route(
		'wpfoundry/v1',
		'/download',
		array(
			'methods'             => 'GET',
			'callback'            => 'wpfoundry_download_file',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);

	register_rest_route(
		'wpfoundry/v1',
		'/upload',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpfoundry_upload_file',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);

	register_rest_route(
		'wpfoundry/v1',
		'/upload-delete',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpfoundry_delete_uploaded_file',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);

	register_rest_route(
		'wpfoundry/v1',
		'/install-from-upload',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpfoundry_install_from_upload',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);
}
add_action( 'rest_api_init', 'wpfoundry_register_management_rest_routes' );

function wpfoundry_uploads_basedir() {
	$uploads = wp_upload_dir();
	if ( empty( $uploads['basedir'] ) ) {
		return untrailingslashit( WP_CONTENT_DIR ) . '/uploads';
	}
	return untrailingslashit( $uploads['basedir'] );
}

function wpfoundry_upload_base_dir() {
	return trailingslashit( wpfoundry_uploads_basedir() ) . 'wpfoundry-helper';
}

function wpfoundry_upload_base_url() {
	$uploads = wp_upload_dir();
	$baseurl = ! empty( $uploads['baseurl'] ) ? $uploads['baseurl'] : content_url( 'uploads' );
	return trailingslashit( $baseurl ) . 'wpfoundry-helper';
}

function wpfoundry_build_cli_env() {
	$env = is_array( $_ENV ) ? $_ENV : array();
	foreach ( array( 'PATH', 'HOME', 'USER', 'LANG', 'LC_ALL', 'TMPDIR' ) as $env_key ) {
		if ( ! isset( $env[ $env_key ] ) || $env[ $env_key ] === '' ) {
			$env_value = getenv( $env_key );
			if ( $env_value !== false && $env_value !== '' ) {
				$env[ $env_key ] = $env_value;
			}
		}
	}
	$env['WP_CLI_CACHE_DIR'] = sys_get_temp_dir() . '/wp-cli-cache';
	return $env;
}

/**
 * Validate and sanitize WP-CLI command input.
 *
 * @param string $command Command string.
 * @return bool
 */
function wpfoundry_validate_command( $command ) {
	if ( preg_match( '/[\r\n]/', $command ) ) {
		return false;
	}

	if ( preg_match( '/[;&|`$()<>]/', $command ) ) {
		return false;
	}

	if ( strlen( $command ) > 1000 ) {
		return false;
	}

	$allowed_commands = array(
		'wp plugin',
		'wp theme',
		'wp core',
		'wp user',
		'wp option',
		'wp post',
		'wp db',
		'wp cache',
		'wpfoundry',
		'wp config',
		'wp site',
		'wp network',
		'wp menu',
		'wp widget',
		'wp sidebar',
		'wp maintenance-mode',
	);

	$normalized = trim( $command );
	if ( stripos( $normalized, 'wp ' ) !== 0 && stripos( $normalized, 'wpfoundry' ) !== 0 ) {
		$normalized = 'wp ' . $normalized;
	}

	$command_start = strtolower( substr( $normalized, 0, 20 ) );
	foreach ( $allowed_commands as $allowed ) {
		if ( strpos( $command_start, $allowed ) === 0 ) {
			return true;
		}
	}

	return false;
}

/**
 * Tokenize a command line respecting single/double quotes (quotes are consumed).
 *
 * @param string $command Command string.
 * @return string[]|false Tokens, or false on unbalanced quotes / empty input.
 */
function wpfoundry_tokenize_command( $command ) {
	$tokens    = array();
	$current   = '';
	$in_single = false;
	$in_double = false;
	$length    = strlen( $command );

	for ( $i = 0; $i < $length; $i++ ) {
		$ch = $command[ $i ];

		if ( $in_single ) {
			if ( $ch === "'" ) {
				$in_single = false;
			} else {
				$current .= $ch;
			}
			continue;
		}

		if ( $in_double ) {
			if ( $ch === '"' ) {
				$in_double = false;
			} else {
				$current .= $ch;
			}
			continue;
		}

		if ( $ch === "'" ) {
			$in_single = true;
			continue;
		}
		if ( $ch === '"' ) {
			$in_double = true;
			continue;
		}
		if ( $ch === ' ' || $ch === "\t" ) {
			if ( $current !== '' ) {
				$tokens[] = $current;
				$current  = '';
			}
			continue;
		}

		$current .= $ch;
	}

	if ( $in_single || $in_double ) {
		return false;
	}
	if ( $current !== '' ) {
		$tokens[] = $current;
	}
	if ( empty( $tokens ) ) {
		return false;
	}

	return $tokens;
}

/**
 * Build a shell-safe command string by escaping each token with escapeshellarg.
 *
 * @param string $command Command string.
 * @return string|false Escaped command, or false if tokenization fails.
 */
function wpfoundry_build_safe_shell_command( $command ) {
	$command_to_run = ( strpos( $command, 'wp ' ) === 0 ) ? $command : "wp $command";
	$tokens         = wpfoundry_tokenize_command( $command_to_run );
	if ( $tokens === false ) {
		return false;
	}

	$escaped = array_map( 'escapeshellarg', $tokens );
	return implode( ' ', $escaped );
}

/**
 * Download a generated backup file by token.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_Error|void
 */
function wpfoundry_download_file( $request ) {
	$token = $request->get_param( 'token' );
	if ( ! $token || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return new WP_Error( 'invalid_token', 'Missing or invalid token', array( 'status' => 400 ) );
	}

	$data = get_transient( 'wpfoundry_dl_' . $token );
	if ( ! $data || ! is_array( $data ) ) {
		$token_file = trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.json';
		if ( file_exists( $token_file ) ) {
			$raw     = @file_get_contents( $token_file );
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) ) {
				$data = $decoded;
			}
		}
	}

	if ( ! $data || ! is_array( $data ) ) {
		$zip_guess = trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.zip';
		if ( file_exists( $zip_guess ) ) {
			$data = array(
				'path'         => $zip_guess,
				'filename'     => 'backup.zip',
				'created_at'   => time(),
				'expires_at'   => time() + 60,
				'delete_after' => true,
			);
		} else {
			return new WP_Error( 'token_not_found', 'Token not found or expired', array( 'status' => 404 ) );
		}
	}

	if ( isset( $data['expires_at'] ) && is_numeric( $data['expires_at'] ) && time() > intval( $data['expires_at'] ) ) {
		wpfoundry_cleanup_download_token( $token, $data );
		return new WP_Error( 'token_not_found', 'Token not found or expired', array( 'status' => 404 ) );
	}

	$path     = isset( $data['path'] ) ? $data['path'] : '';
	$filename = isset( $data['filename'] ) ? $data['filename'] : 'backup.zip';

	if ( ! $path || ! is_string( $path ) || ! file_exists( $path ) ) {
		wpfoundry_cleanup_download_token( $token, $data );
		return new WP_Error( 'file_not_found', 'Backup file not found', array( 'status' => 404 ) );
	}

	$delete_after = ! isset( $data['delete_after'] ) || $data['delete_after'];
	$content_type = 'application/octet-stream';
	if ( preg_match( '/\.zip$/i', $filename ) ) {
		$content_type = 'application/zip';
	}

	header( 'Content-Type: ' . $content_type );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $filename ) ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, no-transform, private, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	header( 'X-Content-Type-Options: nosniff' );

	// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download cannot go through WP_Filesystem or esc_*.
	$fh = fopen( $path, 'rb' );
	if ( $fh === false ) {
		wpfoundry_cleanup_download_token( $token, $data );
		return new WP_Error( 'file_open_failed', 'Failed to open backup file', array( 'status' => 500 ) );
	}

	while ( ! feof( $fh ) ) {
		echo fread( $fh, 1024 * 1024 );
		@ob_flush();
		@flush();
	}
	fclose( $fh );
	// phpcs:enable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.OutputNotEscaped

	if ( ! $delete_after ) {
		delete_transient( 'wpfoundry_dl_' . $token );
		wp_delete_file( trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.json' );
		exit;
	}

	wpfoundry_cleanup_download_token( $token, $data );
	exit;
}

function wpfoundry_cleanup_download_token( $token, $data ) {
	$delete_after = ! isset( $data['delete_after'] ) || $data['delete_after'];
	if ( $delete_after && ! empty( $data['path'] ) && is_string( $data['path'] ) && file_exists( $data['path'] ) ) {
		wp_delete_file( $data['path'] );
	}
	delete_transient( 'wpfoundry_dl_' . $token );
	wp_delete_file( trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.json' );
	$zip_guess = trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.zip';
	if ( $delete_after ) {
		wp_delete_file( $zip_guess );
	}
}

function wpfoundry_store_download_token( $token, array $payload, $ttl ) {
	set_transient( 'wpfoundry_dl_' . $token, $payload, $ttl );
	@file_put_contents(
		trailingslashit( sys_get_temp_dir() ) . 'wpfoundry-dl-' . $token . '.json',
		wp_json_encode( $payload )
	);
}

function wpfoundry_upload_token_json_path( $token ) {
	return trailingslashit( wpfoundry_upload_base_dir() ) . 'wpfoundry-upload-' . $token . '.json';
}

function wpfoundry_upload_default_zip_path( $token ) {
	return trailingslashit( wpfoundry_upload_base_dir() ) . 'wpfoundry-upload-' . $token . '.zip';
}

function wpfoundry_upload_store_token( $token, array $payload, $ttl = 600 ) {
	set_transient( 'wpfoundry_upload_' . $token, $payload, $ttl );
	@file_put_contents( wpfoundry_upload_token_json_path( $token ), wp_json_encode( $payload ) );
}

function wpfoundry_upload_get_token( $token ) {
	$data = get_transient( 'wpfoundry_upload_' . $token );
	if ( ! is_array( $data ) || empty( $data['path'] ) ) {
		$token_file = wpfoundry_upload_token_json_path( $token );
		if ( file_exists( $token_file ) ) {
			$raw     = @file_get_contents( $token_file );
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) && ! empty( $decoded['path'] ) ) {
				$data = $decoded;
			}
		}
	}

	if ( ( ! is_array( $data ) || empty( $data['path'] ) ) && file_exists( wpfoundry_upload_default_zip_path( $token ) ) ) {
		$zip_guess = wpfoundry_upload_default_zip_path( $token );
		$data      = array(
			'path'       => $zip_guess,
			'filename'   => basename( $zip_guess ),
			'created_at' => filemtime( $zip_guess ) ?: time(),
			'expires_at' => ( filemtime( $zip_guess ) ?: time() ) + 600,
			'size'       => filesize( $zip_guess ),
		);
	}

	if ( ! is_array( $data ) || empty( $data['path'] ) ) {
		return null;
	}

	if ( isset( $data['expires_at'] ) && is_numeric( $data['expires_at'] ) && time() > intval( $data['expires_at'] ) ) {
		wpfoundry_upload_delete_token( $token );
		return null;
	}

	return $data;
}

function wpfoundry_upload_delete_token( $token ) {
	$zip_guess = wpfoundry_upload_default_zip_path( $token );
	$json_path = wpfoundry_upload_token_json_path( $token );

	$data = get_transient( 'wpfoundry_upload_' . $token );
	if ( ! is_array( $data ) || empty( $data['path'] ) ) {
		if ( file_exists( $json_path ) ) {
			$raw     = @file_get_contents( $json_path );
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) && ! empty( $decoded['path'] ) ) {
				$data = $decoded;
			}
		}
	}

	delete_transient( 'wpfoundry_upload_' . $token );
	wp_delete_file( $json_path );

	if ( is_array( $data ) && ! empty( $data['path'] ) && file_exists( $data['path'] ) ) {
		wp_delete_file( $data['path'] );
	}
	if ( file_exists( $zip_guess ) ) {
		wp_delete_file( $zip_guess );
	}
}

function wpfoundry_filter_upload_dir( $dirs ) {
	$dirs['subdir'] = '/wpfoundry-helper';
	$dirs['path']   = wpfoundry_upload_base_dir();
	$dirs['url']    = wpfoundry_upload_base_url();
	return $dirs;
}

function wpfoundry_upload_unique_filename( $dir, $filename, $ext ) {
	unset( $dir, $filename, $ext );
	return isset( $GLOBALS['wpfoundry_upload_token'] ) ? $GLOBALS['wpfoundry_upload_token'] . '.zip' : 'upload.zip';
}

function wpfoundry_ensure_upload_dir() {
	$base_dir = wpfoundry_upload_base_dir();
	if ( ! wp_mkdir_p( $base_dir ) ) {
		return false;
	}
	$index = trailingslashit( $base_dir ) . 'index.php';
	if ( ! file_exists( $index ) ) {
		@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}
	return true;
}

function wpfoundry_upload_file( $request ) {
	$files = $request->get_file_params();
	if ( empty( $files['file'] ) ) {
		return new WP_Error( 'missing_file', 'No file uploaded', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$file     = $files['file'];
	$tmp_name = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
	if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
		return new WP_Error( 'invalid_upload', 'Invalid upload data', array( 'status' => 400 ) );
	}

	$original_name = sanitize_file_name( isset( $file['name'] ) ? $file['name'] : 'upload.zip' );
	$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
	if ( $extension !== 'zip' ) {
		return new WP_Error( 'invalid_file_type', 'Only zip files are supported', array( 'status' => 400 ) );
	}

	$tmp_size         = is_file( $tmp_name ) ? filesize( $tmp_name ) : 0;
	$max_upload_bytes = apply_filters( 'wpfoundry_max_upload_bytes', 128 * 1024 * 1024 );
	if ( $tmp_size === false || $tmp_size <= 0 ) {
		return new WP_Error( 'invalid_upload', 'Uploaded file is empty', array( 'status' => 400 ) );
	}
	if ( $tmp_size > $max_upload_bytes ) {
		return new WP_Error(
			'upload_too_large',
			sprintf( 'Upload exceeds maximum size of %d bytes', $max_upload_bytes ),
			array( 'status' => 413 )
		);
	}

	// phpcs:disable WordPress.WP.AlternativeFunctions -- reading zip magic bytes from the PHP upload tmp file.
	$fh = @fopen( $tmp_name, 'rb' );
	if ( ! $fh ) {
		return new WP_Error( 'invalid_upload', 'Failed to read uploaded file', array( 'status' => 400 ) );
	}
	$magic = fread( $fh, 4 );
	fclose( $fh );
	// phpcs:enable WordPress.WP.AlternativeFunctions
	if ( $magic !== "PK\x03\x04" && $magic !== "PK\x05\x06" && $magic !== "PK\x07\x08" ) {
		return new WP_Error( 'invalid_file_type', 'Uploaded file is not a valid zip archive', array( 'status' => 400 ) );
	}

	if ( ! wpfoundry_ensure_upload_dir() ) {
		return new WP_Error( 'upload_dir_failed', 'Failed to create upload directory', array( 'status' => 500 ) );
	}
	$base_dir = wpfoundry_upload_base_dir();
	if ( ! wp_is_writable( $base_dir ) ) {
		return new WP_Error( 'upload_dir_not_writable', 'Upload directory is not writable', array( 'status' => 500 ) );
	}

	$token                              = bin2hex( random_bytes( 16 ) );
	$GLOBALS['wpfoundry_upload_token'] = $token;
	add_filter( 'upload_dir', 'wpfoundry_filter_upload_dir' );
	$uploaded = wp_handle_upload(
		$file,
		array(
			'test_form'                 => false,
			'test_type'                 => false,
			'mimes'                     => array(
				'zip' => 'application/zip',
			),
			'unique_filename_callback'  => 'wpfoundry_upload_unique_filename',
		)
	);
	remove_filter( 'upload_dir', 'wpfoundry_filter_upload_dir' );
	unset( $GLOBALS['wpfoundry_upload_token'] );

	if ( isset( $uploaded['error'] ) ) {
		return new WP_Error( 'upload_move_failed', $uploaded['error'], array( 'status' => 500 ) );
	}

	$dest_path = isset( $uploaded['file'] ) ? $uploaded['file'] : wpfoundry_upload_default_zip_path( $token );
	$safe_name = basename( $dest_path );
	$size      = file_exists( $dest_path ) ? filesize( $dest_path ) : 0;
	$payload   = array(
		'path'       => $dest_path,
		'filename'   => $safe_name,
		'created_at' => time(),
		'expires_at' => time() + 600,
		'size'       => $size !== false ? $size : 0,
	);
	wpfoundry_upload_store_token( $token, $payload, 600 );

	return rest_ensure_response(
		array(
			'success'    => true,
			'token'      => $token,
			'path'       => $dest_path,
			'filename'   => $safe_name,
			'size'       => $payload['size'],
			'expires_in' => 600,
		)
	);
}

function wpfoundry_install_from_upload_filesystem_method( $method ) {
	unset( $method );
	return 'direct';
}

function wpfoundry_install_from_upload_filesystem_credentials( $credentials, $form_post, $type ) {
	unset( $credentials, $form_post, $type );
	return true;
}

function wpfoundry_install_from_upload( $request ) {
	$params = $request->get_json_params();
	$token  = $params['token'] ?? '';
	$type   = isset( $params['type'] ) ? strtolower( trim( $params['type'] ) ) : 'plugin';

	if ( ! $token || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return new WP_Error( 'invalid_token', 'Missing or invalid token', array( 'status' => 400 ) );
	}
	if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
		return new WP_Error( 'invalid_type', 'Type must be plugin or theme', array( 'status' => 400 ) );
	}

	$data = wpfoundry_upload_get_token( $token );
	if ( ! is_array( $data ) || empty( $data['path'] ) || ! file_exists( $data['path'] ) ) {
		return new WP_Error( 'token_not_found', 'Upload token not found or expired', array( 'status' => 404 ) );
	}

	$path = $data['path'];
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	add_filter( 'filesystem_method', 'wpfoundry_install_from_upload_filesystem_method', 10, 1 );
	add_filter( 'request_filesystem_credentials', 'wpfoundry_install_from_upload_filesystem_credentials', 10, 3 );
	$result = null;
	try {
		if ( $type === 'plugin' ) {
			$upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $path, array( 'overwrite_package' => true ) );
		} else {
			$upgrader = new Theme_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $path, array( 'overwrite_package' => true ) );
		}
	} finally {
		remove_filter( 'filesystem_method', 'wpfoundry_install_from_upload_filesystem_method', 10 );
		remove_filter( 'request_filesystem_credentials', 'wpfoundry_install_from_upload_filesystem_credentials', 10 );
	}

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'install_failed', $result->get_error_message(), array( 'status' => 500 ) );
	}
	if ( ! $result ) {
		return new WP_Error( 'install_failed', 'Installation failed', array( 'status' => 500 ) );
	}

	wpfoundry_upload_delete_token( $token );

	return rest_ensure_response(
		array(
			'success' => true,
			'message' => ucfirst( $type ) . ' installed successfully',
		)
	);
}

function wpfoundry_delete_uploaded_file( $request ) {
	$token = $request->get_param( 'token' );
	if ( ! $token || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
		return new WP_Error( 'invalid_token', 'Missing or invalid token', array( 'status' => 400 ) );
	}

	wpfoundry_upload_delete_token( $token );

	return rest_ensure_response(
		array(
			'success' => true,
			'deleted' => true,
		)
	);
}

/**
 * Full-edition /run handler: allowlisted WP-CLI plus helper subcommands.
 *
 * @param string $command Command string.
 */
function wpfoundry_run_management_command( $command ) {
	try {
		$runner = new WPFoundry_Command_Runner();
		$runner->execute_command( $command );
	} catch ( Exception $e ) {
		$event = array(
			'type'      => 'command_error',
			'timestamp' => microtime( true ),
			'data'      => array(
				'error'   => 'php_exception',
				'message' => $e->getMessage(),
				'command' => $command,
			),
		);
		echo "event: command_error\n";
		echo 'data: ' . wp_json_encode( $event ) . "\n\n";
		@ob_flush();
		@flush();
	}
}
