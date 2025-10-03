<?php
/*
Plugin Name: WP Foundry Agent (Final 6.3+)
Description: Secure REST API for WP Foundry using proper Application Password authentication with debug logs
Version: 2.1
Author: You
*/

if (!defined('ABSPATH')) exit;

/**
 * REST API endpoint
 */
add_action('rest_api_init', function () {

    register_rest_route('foundry/v1', '/run', [
        'methods' => 'POST',
        'callback' => function($request) {

            $log = [];

            // Detect Basic Auth headers
            $username = $_SERVER['PHP_AUTH_USER'] ?? null;
            $password = $_SERVER['PHP_AUTH_PW'] ?? null;

            // Fallback for servers stripping Authorization headers
            if (!$username || !$password) {
                if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) &&
                    strpos($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 'Basic ') === 0) {
                    $basic = base64_decode(substr($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 6));
                    list($username, $password) = explode(':', $basic, 2);
                }
            }

            $log[] = "PHP_AUTH_USER=" . ($username ?? '(empty)');
            $log[] = "PHP_AUTH_PW=" . ($password ? '******' : '(empty)');

            if (!$username || !$password) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Missing authentication headers',
                    'log' => $log
                ], 401);
            }

            // Lookup user by login/email
            $user = get_user_by('login', $username);
            if (!$user) {
                $log[] = "User not found by login, trying email";
                $user = get_user_by('email', $username);
            }

            if (!$user) {
                $log[] = "User not found at all";
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Invalid username',
                    'log' => $log
                ], 403);
            }

            $log[] = "User found: ID=" . $user->ID;

            // Authenticate using WordPress Application Password
            if (!function_exists('wp_authenticate_application_password')) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'wp_authenticate_application_password() not available',
                    'log' => $log
                ], 500);
            }

            // ⚠ Pass $request as 3rd argument to satisfy WP 6.3+ requirement
            $auth_result = wp_authenticate_application_password($user, $password, $request);
            if (is_wp_error($auth_result)) {
                $log[] = "Authentication failed: " . $auth_result->get_error_message();
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Invalid credentials',
                    'log' => $log
                ], 403);
            }

            $log[] = "Authentication successful";

            // Capability check
            if (!user_can($auth_result, 'manage_options')) {
                $log[] = "User lacks manage_options capability";
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Insufficient permissions',
                    'log' => $log
                ], 403);
            }

            $log[] = "User has admin permissions";

            // Get command and args
            $params = $request->get_json_params();
            $command = $params['command'] ?? '';
            $args = $params['args'] ?? [];

            $log[] = "Command requested: $command";
            $log[] = "Args: " . json_encode($args);

            if (empty($command)) {
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'No command specified',
                    'log' => $log
                ], 400);
            }

            // Optional whitelist
            $allowed = ['plugin', 'theme', 'core', 'user', 'site'];
            if (!in_array($command, $allowed)) {
                $log[] = "Command not allowed";
                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Command not allowed',
                    'log' => $log
                ], 403);
            }

            // Execute WP-CLI
            $escapedArgs = array_map('escapeshellarg', $args);
            $cmdLine = 'wp ' . escapeshellarg($command) . ' ' . implode(' ', $escapedArgs);
            $log[] = "Executing shell command: $cmdLine";

            $output = [];
            $exitCode = 0;
            exec($cmdLine . ' 2>&1', $output, $exitCode);

            $log[] = "Command exit code: $exitCode";
            $log[] = "Command output: " . implode("\n", $output);

            return [
                'success' => true,
                'command' => $command,
                'args' => $args,
                'output' => $output,
                'exit_code' => $exitCode,
                'log' => $log
            ];
        },
        'permission_callback' => '__return_true'
    ]);

});
