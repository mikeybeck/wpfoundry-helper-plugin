<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this copy is the WordPress.org pairing stub.
 */
function wpfoundry_is_directory_edition() {
	return defined( 'WPFOUNDRY_HELPER_EDITION' ) && 'directory' === WPFOUNDRY_HELPER_EDITION;
}

/**
 * Feature flags advertised to the WP Foundry desktop app.
 */
function wpfoundry_helper_capabilities() {
	if ( wpfoundry_is_directory_edition() ) {
		return array(
			'run',
			'rotate_secret',
			'dotorg_updates',
		);
	}

	return array(
		'run',
		'cli',
		'download',
		'upload',
		'upload_delete',
		'install_from_upload',
		'rotate_secret',
	);
}

/**
 * Payload for `wpfoundry helper-version` (pairing stub and full edition).
 *
 * @return array<string, mixed>
 */
function wpfoundry_helper_version_payload() {
	$version = defined( 'WPFOUNDRY_HELPER_VERSION' ) ? WPFOUNDRY_HELPER_VERSION : '';
	$slug    = '';
	if ( defined( 'WPFOUNDRY_HELPER_FILE' ) ) {
		$dir = dirname( plugin_basename( WPFOUNDRY_HELPER_FILE ) );
		$slug = ( '.' === $dir ) ? '' : $dir;
	}

	return array(
		'status'             => 'success',
		'version'            => $version,
		'slug'               => $slug,
		'edition'            => defined( 'WPFOUNDRY_HELPER_EDITION' ) ? WPFOUNDRY_HELPER_EDITION : 'full',
		'min_supported_app'  => WPFOUNDRY_HELPER_MIN_APP_VERSION,
		'max_supported_app'  => WPFOUNDRY_HELPER_MAX_APP_VERSION,
		'protocol_version'   => WPFOUNDRY_HELPER_PROTOCOL_VERSION,
		'protocol_min'       => WPFOUNDRY_HELPER_PROTOCOL_MIN,
		'protocol_max'       => WPFOUNDRY_HELPER_PROTOCOL_MAX,
		'capabilities'       => wpfoundry_helper_capabilities(),
	);
}

/**
 * Register HMAC-authenticated REST routes shared by both editions.
 */
function wpfoundry_register_rest_routes() {
	register_rest_route(
		'wpfoundry/v1',
		'/run',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpfoundry_run_command_sse',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);

	register_rest_route(
		'wpfoundry/v1',
		'/rotate-secret',
		array(
			'methods'             => 'POST',
			'callback'            => 'wpfoundry_rotate_shared_secret',
			'permission_callback' => 'wpfoundry_verify_hmac_auth',
		)
	);
}

function wpfoundry_register_rewrite() {
	add_rewrite_rule( '^wp-foundry/v1/endpoint/?$', 'index.php?wpfoundry_endpoint=1', 'top' );
}

function wpfoundry_register_query_vars( $vars ) {
	$vars[] = 'wpfoundry_endpoint';
	return $vars;
}

function wpfoundry_handle_custom_endpoint() {
	if ( intval( get_query_var( 'wpfoundry_endpoint' ) ) !== 1 ) {
		return;
	}

	$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	if ( $request_method !== 'POST' ) {
		status_header( 405 );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'error' => 'method_not_allowed' ) );
		exit;
	}

	$raw_body = file_get_contents( 'php://input' );
	$body     = is_string( $raw_body ) ? $raw_body : '';
	$headers  = wpfoundry_get_request_headers();

	// HMAC uses the raw query string as part of the signature; this is not a form handler.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$query_params = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();

	$auth_result = wpfoundry_verify_hmac_from_parts(
		'POST',
		'/wp-foundry/v1/endpoint',
		wpfoundry_canonical_query( $query_params ),
		$body,
		$headers
	);
	if ( $auth_result !== true ) {
		status_header( 401 );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'error' => 'Unauthorized' ) );
		exit;
	}

	$decoded = json_decode( $body, true );
	$command = is_array( $decoded ) ? ( $decoded['command'] ?? '' ) : '';

	wpfoundry_run_command_sse_with_command( $command );
}

function wpfoundry_get_shared_secret( $force_regenerate = false ) {
	$secret = get_option( 'wpfoundry_shared_secret' );
	if ( $force_regenerate ) {
		return wpfoundry_rotate_secret();
	}

	if ( ! $secret || ! is_string( $secret ) || strlen( $secret ) < 32 ) {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( 'wpfoundry_shared_secret', $secret, false );
	}
	return $secret;
}

function wpfoundry_get_previous_secret() {
	$prev    = get_option( 'wpfoundry_prev_shared_secret' );
	$expires = get_option( 'wpfoundry_prev_shared_secret_expires' );
	if ( ! $prev || ! is_string( $prev ) ) {
		return '';
	}

	if ( ! is_numeric( $expires ) || time() > intval( $expires ) ) {
		delete_option( 'wpfoundry_prev_shared_secret' );
		delete_option( 'wpfoundry_prev_shared_secret_expires' );
		return '';
	}

	return $prev;
}

function wpfoundry_rotate_secret( $grace_seconds = WPFOUNDRY_HELPER_PREVIOUS_SECRET_TTL ) {
	$current    = get_option( 'wpfoundry_shared_secret' );
	$new_secret = bin2hex( random_bytes( 32 ) );

	if ( $current && is_string( $current ) ) {
		update_option( 'wpfoundry_prev_shared_secret', $current, false );
		update_option( 'wpfoundry_prev_shared_secret_expires', time() + intval( $grace_seconds ), false );
	} else {
		delete_option( 'wpfoundry_prev_shared_secret' );
		delete_option( 'wpfoundry_prev_shared_secret_expires' );
	}

	update_option( 'wpfoundry_shared_secret', $new_secret, false );

	return $new_secret;
}

function wpfoundry_get_header_value( $request, $header_name ) {
	$value = $request->get_header( $header_name );
	return is_string( $value ) ? trim( $value ) : '';
}

function wpfoundry_get_request_headers() {
	if ( function_exists( 'getallheaders' ) ) {
		$headers = getallheaders();
		if ( is_array( $headers ) ) {
			return $headers;
		}
	}

	$headers = array();
	foreach ( $_SERVER as $key => $value ) {
		if ( strpos( $key, 'HTTP_' ) === 0 ) {
			$name             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $key, 5 ) ) ) ) );
			$headers[ $name ] = $value;
		}
	}
	return $headers;
}

function wpfoundry_get_header_from_array( $headers, $header_name ) {
	if ( ! is_array( $headers ) ) {
		return '';
	}

	$needle = strtolower( $header_name );
	foreach ( $headers as $key => $value ) {
		if ( strtolower( $key ) === $needle ) {
			return is_string( $value ) ? trim( $value ) : '';
		}
	}

	return '';
}

function wpfoundry_canonical_query( $params ) {
	if ( ! is_array( $params ) || empty( $params ) ) {
		return '';
	}

	ksort( $params );
	$pairs = array();
	foreach ( $params as $key => $value ) {
		if ( is_array( $value ) ) {
			sort( $value );
			foreach ( $value as $item ) {
				$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $item );
			}
		} else {
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}
	}

	return implode( '&', $pairs );
}

function wpfoundry_is_nonce_used( $nonce ) {
	$key = 'wpfoundry_nonce_' . hash( 'sha256', $nonce );
	return (bool) get_transient( $key );
}

function wpfoundry_store_nonce( $nonce, $ttl_seconds ) {
	$key = 'wpfoundry_nonce_' . hash( 'sha256', $nonce );
	set_transient( $key, time(), $ttl_seconds );
}

function wpfoundry_verify_hmac_from_parts( $method, $route, $query, $body, $headers ) {
	$signature  = wpfoundry_get_header_from_array( $headers, 'x-wpf-signature' );
	$timestamp  = wpfoundry_get_header_from_array( $headers, 'x-wpf-ts' );
	$nonce      = wpfoundry_get_header_from_array( $headers, 'x-wpf-nonce' );
	$body_hash  = wpfoundry_get_header_from_array( $headers, 'x-wpf-body-sha256' );
	$request_id = wpfoundry_get_header_from_array( $headers, 'x-wpf-request-id' );

	if ( $signature === '' || $timestamp === '' || $nonce === '' || $body_hash === '' ) {
		wpfoundry_log_auth_event( 'failure', 'missing_headers', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	if ( ! preg_match( '/^[a-f0-9]{64}$/i', $body_hash ) ) {
		wpfoundry_log_auth_event( 'failure', 'invalid_body_hash', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	if ( ! preg_match( '/^[a-f0-9]{16,128}$/i', $nonce ) ) {
		wpfoundry_log_auth_event( 'failure', 'invalid_nonce', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	$ts_int = intval( $timestamp );
	if ( $ts_int <= 0 || abs( time() - $ts_int ) > 300 ) {
		wpfoundry_log_auth_event( 'failure', 'timestamp_invalid', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	if ( wpfoundry_is_nonce_used( $nonce ) ) {
		wpfoundry_log_auth_event( 'failure', 'nonce_replay', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	$computed_body_hash = hash( 'sha256', is_string( $body ) ? $body : '' );
	if ( ! hash_equals( strtolower( $body_hash ), strtolower( $computed_body_hash ) ) ) {
		wpfoundry_log_auth_event( 'failure', 'body_hash_mismatch', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	$base_string = implode(
		"\n",
		array(
			strtoupper( $method ),
			$route,
			$query,
			strtolower( $body_hash ),
			(string) $ts_int,
			strtolower( $nonce ),
			$request_id,
		)
	);

	$secret   = wpfoundry_get_shared_secret( false );
	$expected = hash_hmac( 'sha256', $base_string, $secret );

	$match       = hash_equals( $expected, strtolower( $signature ) );
	$used_secret = '';
	if ( $match ) {
		$used_secret = $secret;
	}
	if ( ! $match ) {
		$previous = wpfoundry_get_previous_secret();
		if ( $previous !== '' ) {
			$expected_prev = hash_hmac( 'sha256', $base_string, $previous );
			$match         = hash_equals( $expected_prev, strtolower( $signature ) );
			if ( $match ) {
				$used_secret = $previous;
			}
		}
	}

	if ( ! $match ) {
		wpfoundry_log_auth_event( 'failure', 'signature_mismatch', $request_id );
		return new WP_Error( 'wpfoundry_auth_failed', 'Unauthorized', array( 'status' => 401 ) );
	}

	if ( $used_secret !== '' && ! wpfoundry_check_rate_limit_for_secret( $used_secret ) ) {
		wpfoundry_log_auth_event( 'failure', 'rate_limited_secret', $request_id );
		return new WP_Error( 'rate_limited', 'WPF Rate limit exceeded for site', array( 'status' => 429 ) );
	}

	wpfoundry_store_nonce( $nonce, 600 );
	return true;
}

function wpfoundry_verify_hmac_auth( $request ) {
	$headers = array(
		'x-wpf-signature'    => wpfoundry_get_header_value( $request, 'x-wpf-signature' ),
		'x-wpf-ts'           => wpfoundry_get_header_value( $request, 'x-wpf-ts' ),
		'x-wpf-nonce'        => wpfoundry_get_header_value( $request, 'x-wpf-nonce' ),
		'x-wpf-body-sha256'  => wpfoundry_get_header_value( $request, 'x-wpf-body-sha256' ),
		'x-wpf-request-id'   => wpfoundry_get_header_value( $request, 'x-wpf-request-id' ),
	);

	return wpfoundry_verify_hmac_from_parts(
		$request->get_method(),
		$request->get_route(),
		wpfoundry_canonical_query( $request->get_query_params() ),
		$request->get_body(),
		$headers
	);
}

function wpfoundry_check_rate_limit() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$key = 'wpfoundry_rate_limit_' . substr( hash( 'sha256', $ip ), 0, 32 );
	$now = time();

	$data = get_transient( $key );
	if ( ! $data ) {
		$data = array(
			'count' => 0,
			'reset' => $now + 60,
		);
	}

	if ( $now > $data['reset'] ) {
		$data = array(
			'count' => 0,
			'reset' => $now + 60,
		);
	}

	if ( $data['count'] >= 30 ) {
		return false;
	}

	$data['count']++;
	set_transient( $key, $data, 60 );

	return true;
}

function wpfoundry_check_rate_limit_for_secret( $secret ) {
	if ( ! is_string( $secret ) || $secret === '' ) {
		return true;
	}

	$key  = 'wpfoundry_rate_limit_secret_' . hash( 'sha256', $secret );
	$now  = time();
	$data = get_transient( $key );
	if ( ! $data ) {
		$data = array(
			'count' => 0,
			'reset' => $now + 60,
		);
	}

	if ( $now > $data['reset'] ) {
		$data = array(
			'count' => 0,
			'reset' => $now + 60,
		);
	}

	if ( $data['count'] >= 30 ) {
		return false;
	}

	$data['count']++;
	set_transient( $key, $data, 60 );
	return true;
}

function wpfoundry_log_auth_event( $status, $reason, $request_id = '' ) {
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	$entry = array(
		'event'      => 'wpf_auth',
		'status'     => $status,
		'reason'     => $reason,
		'ip'         => $ip,
		'request_id' => $request_id,
	);
	error_log( 'WPFoundryAuth ' . wp_json_encode( $entry ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- HMAC auth audit trail, not debug leftover.
}

function wpfoundry_rotate_shared_secret( $request ) {
	unset( $request );
	if ( ! wpfoundry_check_rate_limit() ) {
		return new WP_Error( 'rate_limited', 'WPF Rate limit exceeded for site', array( 'status' => 429 ) );
	}

	$new_secret = wpfoundry_rotate_secret();
	$expires    = get_option( 'wpfoundry_prev_shared_secret_expires' );

	return rest_ensure_response(
		array(
			'success'             => true,
			'shared_secret'       => $new_secret,
			'previous_expires_at' => is_numeric( $expires ) ? intval( $expires ) : null,
			'grace_seconds'       => WPFOUNDRY_HELPER_PREVIOUS_SECRET_TTL,
		)
	);
}

/**
 * Whether a command is allowed on the pairing stub (no WP-CLI).
 *
 * @param string $command Command string.
 * @return bool
 */
function wpfoundry_is_pairing_command( $command ) {
	$normalized = strtolower( trim( (string) $command ) );
	if ( strpos( $normalized, 'wp ' ) === 0 ) {
		$normalized = trim( substr( $normalized, 3 ) );
	}
	if ( strpos( $normalized, 'wpfoundry ' ) === 0 ) {
		$normalized = trim( substr( $normalized, 10 ) );
	}
	return in_array( $normalized, array( 'helper-version', 'version' ), true );
}

function wpfoundry_begin_sse() {
	header( 'Content-Type: text/event-stream' );
	header( 'Cache-Control: no-cache' );
	header( 'Connection: keep-alive' );
	header( 'X-Accel-Buffering: no' );
	// phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- scoped to this SSE command stream so proxies/PHP do not buffer or time out mid-command.
	@ini_set( 'output_buffering', 'off' );
	@ini_set( 'zlib.output_compression', '0' );
	@ini_set( 'max_execution_time', '0' );
	@set_time_limit( 0 );
	// phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
	@ignore_user_abort( true );
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', '1' );
	}
	while ( ob_get_level() > 0 ) {
		@ob_end_flush();
	}
	@flush();
	echo ": ping\n\n";
	@ob_flush();
	@flush();
}

function wpfoundry_emit_sse_event( $type, $data = array() ) {
	$event = array(
		'type'      => $type,
		'timestamp' => microtime( true ),
		'data'      => $data,
	);
	echo 'event: ' . esc_html( $type ) . "\n";
	echo 'data: ' . wp_json_encode( $event ) . "\n\n";
	@ob_flush();
	@flush();
}

function wpfoundry_run_pairing_command( $command ) {
	$normalized = strtolower( trim( (string) $command ) );
	if ( strpos( $normalized, 'wp ' ) === 0 ) {
		$normalized = trim( substr( $normalized, 3 ) );
	}
	if ( strpos( $normalized, 'wpfoundry ' ) === 0 ) {
		$normalized = 'wpfoundry ' . trim( substr( $normalized, 10 ) );
	} elseif ( in_array( $normalized, array( 'helper-version', 'version' ), true ) ) {
		$normalized = 'wpfoundry ' . $normalized;
	}

	if ( $normalized === 'wpfoundry helper-version' ) {
		$payload = wpfoundry_helper_version_payload();
		wpfoundry_emit_sse_event(
			'command_start',
			array(
				'command'     => 'wpfoundry helper-version',
				'wp_command'  => 'wpfoundry',
				'start_time'  => microtime( true ),
			)
		);
		wpfoundry_emit_sse_event(
			'command_data',
			array(
				'data'        => $payload,
				'line_number' => 1,
				'raw_line'    => wp_json_encode( $payload ),
			)
		);
		wpfoundry_emit_sse_event(
			'command_complete',
			array(
				'exit_code'   => 0,
				'status'      => 'success',
				'total_lines' => 1,
				'end_time'    => microtime( true ),
			)
		);
		return;
	}

	if ( $normalized === 'wpfoundry version' ) {
		$version = defined( 'WPFOUNDRY_HELPER_VERSION' ) ? WPFOUNDRY_HELPER_VERSION : '';
		$body    = array(
			array(
				'status'  => 'success',
				'version' => $version,
			),
		);
		wpfoundry_emit_sse_event(
			'command_start',
			array(
				'command'    => 'wpfoundry version',
				'wp_command' => 'wpfoundry',
				'start_time' => microtime( true ),
			)
		);
		wpfoundry_emit_sse_event(
			'command_data',
			array(
				'data'        => $body,
				'line_number' => 1,
				'raw_line'    => wp_json_encode( $body[0] ),
			)
		);
		wpfoundry_emit_sse_event(
			'command_complete',
			array(
				'success'   => true,
				'exit_code' => 0,
				'end_time'  => microtime( true ),
			)
		);
		return;
	}

	wpfoundry_emit_sse_event(
		'command_error',
		array(
			'error'   => 'directory_edition',
			'message' => 'This WordPress.org plugin only pairs the site. Install the full WP Foundry Helper from https://wpfoundry.app to run management commands.',
			'command' => $command,
		)
	);
	wpfoundry_emit_sse_event(
		'command_complete',
		array(
			'success'   => false,
			'exit_code' => 1,
			'end_time'  => microtime( true ),
		)
	);
}

function wpfoundry_run_command_sse( $request ) {
	$params  = $request->get_json_params();
	$command = $params['command'] ?? '';

	return wpfoundry_run_command_sse_with_command( $command );
}

function wpfoundry_run_command_sse_with_command( $command ) {
	if ( ! wpfoundry_check_rate_limit() ) {
		status_header( 429 );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'error' => 'WPF Rate limit exceeded for site' ) );
		exit;
	}

	if ( empty( $command ) || ! is_string( $command ) ) {
		status_header( 400 );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'error' => 'Invalid command' ) );
		exit;
	}

	if ( function_exists( 'wpfoundry_run_management_command' ) ) {
		if ( ! wpfoundry_validate_command( $command ) ) {
			status_header( 400 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'error' => 'Invalid command' ) );
			exit;
		}
		wpfoundry_begin_sse();
		wpfoundry_run_management_command( $command );
		exit;
	}

	wpfoundry_begin_sse();
	wpfoundry_run_pairing_command( $command );
	exit;
}
