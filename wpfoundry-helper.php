<?php
/*
Plugin Name: WP Foundry Helper
Description: Execute WP-CLI commands via REST with structured real-time streaming (SSE).
Version: 3.2
Author: Mikey
*/

add_action('rest_api_init', function () {
    register_rest_route('wpfoundry/v1', '/run', [
        'methods' => 'POST',
        'callback' => 'wpf_run_command_sse',
        'permission_callback' => function () {
            return current_user_can('administrator');
        },
    ]);
});

class WPFCommandRunner {

    private function emit_event($type, $data = []) {
        $event = [
            'type' => $type,
            'timestamp' => microtime(true),
            'data' => $data
        ];
        echo "event: $type\n";
        echo "data: " . json_encode($event) . "\n\n";
        @ob_flush(); @flush();
    }

    private function execute_wpfoundry_command($command) {
        // Parse the wpfoundry command
        $parts = explode(' ', trim($command));
        array_shift($parts); // Remove 'wpfoundry'

        if (empty($parts)) {
            $this->emit_event('command_error', [
                'error' => 'invalid_command',
                'message' => 'No wpfoundry subcommand specified',
                'command' => $command
            ]);
            return;
        }

        $subcommand = $parts[0];
        $args = array_slice($parts, 1);

        switch ($subcommand) {
            case 'version':
                $this->wpfoundry_version();
                break;
            case 'list-files':
                $this->wpfoundry_list_files($args);
                break;
            default:
                $this->emit_event('command_error', [
                    'error' => 'unknown_subcommand',
                    'message' => "Unknown wpfoundry subcommand: $subcommand",
                    'command' => $command
                ]);
                return;
        }
    }

    private function wpfoundry_version() {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry version',
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $version = '1.0.0'; // Built-in version

        $this->emit_event('command_data', [
            'data' => [['status' => 'success', 'version' => $version]],
            'line_number' => 1,
            'raw_line' => json_encode(['status' => 'success', 'version' => $version])
        ]);

        $this->emit_event('command_complete', [
            'exit_code' => 0,
            'status' => 'success',
            'total_lines' => 1,
            'end_time' => microtime(true)
        ]);
    }

    private function execute_core_version_command() {
        $this->emit_event('command_start', [
            'command' => 'core version',
            'wp_command' => 'wp core',
            'start_time' => microtime(true)
        ]);

        $version = get_bloginfo('version');

        $this->emit_event('command_data', [
            'data' => ['version' => $version],
            'line_number' => 1,
            'raw_line' => json_encode(['version' => $version])
        ]);

        $this->emit_event('command_complete', [
            'exit_code' => 0,
            'status' => 'success',
            'total_lines' => 1,
            'end_time' => microtime(true)
        ]);
    }

    private function wpfoundry_list_files($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry list-files ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        // Parse arguments
        $options = $this->parse_list_files_args($args);

        try {
            $files = $this->scan_files($options);
            $result = [
                'status' => 'success',
                'count' => count($files),
                'files' => $files
            ];

            $this->emit_event('command_data', [
                'data' => [$result],
                'line_number' => 1,
                'raw_line' => json_encode($result)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);

        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'list_files_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        }
    }

    private function parse_list_files_args($args) {
        $options = [
            'type' => 'all',
            'path' => '',
            'recursive' => true,
            'max_depth' => 3,
            'include' => [],
            'exclude' => []
        ];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if (strpos($arg, '--') === 0) {
                $option = substr($arg, 2);
                if (isset($args[$i + 1]) && strpos($args[$i + 1], '--') !== 0) {
                    $value = $args[$i + 1];
                    $i++; // Skip next arg

                    switch ($option) {
                        case 'type':
                            $options['type'] = $value;
                            break;
                        case 'path':
                            $options['path'] = $value;
                            break;
                        case 'max-depth':
                            $options['max_depth'] = intval($value);
                            break;
                        case 'include':
                            $options['include'] = explode(',', $value);
                            break;
                        case 'exclude':
                            $options['exclude'] = explode(',', $value);
                            break;
                    }
                } elseif ($option === 'recursive') {
                    $options['recursive'] = true;
                } elseif ($option === 'no-recursive') {
                    $options['recursive'] = false;
                }
            }
        }

        return $options;
    }

    private function scan_files($options) {
        $base_paths = [
            'core' => ABSPATH,
            'plugins' => WP_PLUGIN_DIR,
            'themes' => WP_CONTENT_DIR . '/themes',
            'uploads' => WP_CONTENT_DIR . '/uploads',
            'content' => WP_CONTENT_DIR
        ];

        if (!empty($options['path'])) {
            $scan_path = ABSPATH . ltrim($options['path'], '/');
        } else {
            $scan_path = isset($base_paths[$options['type']]) ? $base_paths[$options['type']] : ABSPATH;
        }

        if (!is_dir($scan_path)) {
            throw new Exception("Directory does not exist: $scan_path");
        }

        return $this->scan_directory($scan_path, $scan_path, $options, 0);
    }

    private function scan_directory($dir, $base_path, $options, $current_depth) {
        $files = [];

        if (!$options['recursive'] && $current_depth > 0) {
            return $files;
        }

        if ($current_depth > $options['max_depth']) {
            return $files;
        }

        $items = scandir($dir);
        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full_path = $dir . '/' . $item;
            $relative_path = substr($full_path, strlen($base_path));

            // Check exclude patterns
            if ($this->matches_patterns($relative_path, $options['exclude'])) {
                continue;
            }

            // Check include patterns (if specified)
            if (!empty($options['include']) && !$this->matches_patterns($relative_path, $options['include'])) {
                continue;
            }

            if (is_dir($full_path)) {
                $files = array_merge($files, $this->scan_directory($full_path, $base_path, $options, $current_depth + 1));
            } else {
                $files[] = [
                    'path' => ltrim($relative_path, '/'),
                    'full_path' => $full_path,
                    'size' => filesize($full_path),
                    'modified' => filemtime($full_path),
                    'type' => $this->get_file_type($full_path),
                    'readable' => is_readable($full_path),
                    'writable' => is_writable($full_path)
                ];
            }
        }

        return $files;
    }

    private function matches_patterns($path, $patterns) {
        if (empty($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }

            // Simple wildcard matching
            $regex = str_replace(['*', '?'], ['.*', '.'], preg_quote($pattern, '/'));
            if (preg_match('/^' . $regex . '$/i', basename($path))) {
                return true;
            }
        }

        return false;
    }

    private function get_file_type($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        $types = [
            'php' => 'php',
            'js' => 'javascript',
            'css' => 'stylesheet',
            'scss' => 'scss',
            'sass' => 'sass',
            'html' => 'html',
            'xml' => 'xml',
            'json' => 'json',
            'jpg' => 'image',
            'jpeg' => 'image',
            'png' => 'image',
            'gif' => 'image',
            'svg' => 'image',
            'pdf' => 'document',
        ];

        return $types[$extension] ?? 'unknown';
    }

    public function execute_command($command) {
        // Handle special commands that need JSON output
        if ($command === 'wpfoundry core-version') {
            return $this->execute_core_version_command();
        }

        // Handle wpfoundry commands directly (bypass WP-CLI package issues)
        if (strpos($command, 'wpfoundry ') === 0) {
            return $this->execute_wpfoundry_command($command);
        }

        // Parse command to extract structured info
        $command_parts = explode(' ', trim($command));
        $wp_command = $command_parts[0] ?? $command;

        // Emit command start event
        $this->emit_event('command_start', [
            'command' => $command,
            'wp_command' => $wp_command,
            'start_time' => microtime(true)
        ]);

        // Execute WP-CLI command
        $descriptorspec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        // SECURITY: Sanitize command for shell execution
        $safe_command = escapeshellcmd("wp $command");

        // Set WP-CLI cache dir to a writable location
        $env = $_ENV;
        $env['WP_CLI_CACHE_DIR'] = sys_get_temp_dir() . '/wp-cli-cache';

        $process = proc_open($safe_command . " 2>&1", $descriptorspec, $pipes, ABSPATH, $env);
        if (!is_resource($process)) {
            $this->emit_event('command_error', [
                'error' => 'failed_to_start',
                'message' => 'Failed to start WP-CLI process',
                'command' => $command
            ]);
            return;
        }

        // Stream output with progress tracking
        $reader = $pipes[1];
        $output_lines = 0;
        $last_output_time = microtime(true);

        while (!feof($reader)) {
            $line = fgets($reader);
            if ($line !== false) {
                $trimmed_line = trim($line);

                // Skip empty lines to avoid unnecessary events
                if ($trimmed_line === '') {
                    continue;
                }

                $output_lines++;
                $current_time = microtime(true);

                // Determine if this looks like structured data (JSON)
                $json_decoded = json_decode($trimmed_line, true);

                if ($json_decoded !== null) {
                    // Structured JSON output
                    $this->emit_event('command_data', [
                        'data' => $json_decoded,
                        'line_number' => $output_lines,
                        'raw_line' => $trimmed_line
                    ]);
                } else {
                    // Regular text output
                    $this->emit_event('command_output', [
                        'output' => $trimmed_line,
                        'line_number' => $output_lines,
                        'level' => $this->determine_output_level($trimmed_line)
                    ]);
                }

                // Emit progress update every 10 lines or 2 seconds
                if ($output_lines % 10 === 0 || ($current_time - $last_output_time) > 2) {
                    $this->emit_event('command_progress', [
                        'lines_processed' => $output_lines,
                        'elapsed_time' => $current_time - microtime(true) + ($output_lines * 0.1) // rough estimate
                    ]);
                    $last_output_time = $current_time;
                }
            }
        }

        fclose($pipes[1]);
        $return_value = proc_close($process);

        // Determine success/failure
        $is_success = $return_value === 0;

        if ($is_success) {
            $this->emit_event('command_complete', [
                'exit_code' => $return_value,
                'status' => 'success',
                'total_lines' => $output_lines,
                'end_time' => microtime(true)
            ]);
        } else {
            $this->emit_event('command_error', [
                'exit_code' => $return_value,
                'status' => 'failed',
                'error' => 'command_failed',
                'message' => "Command failed with exit code $return_value",
                'total_lines' => $output_lines
            ]);
        }
    }

    private function determine_output_level($line) {
        // Determine log level based on content patterns
        $line_lower = strtolower($line);

        // Check for actual error messages (not plugin names with "error" in them)
        if ((strpos($line_lower, ' error ') !== false) ||
            (preg_match('/fatal|critical|failed|exception/', $line_lower)) ||
            (preg_match('/^\s*error:/i', $line_lower))) {
            return 'error';
        } elseif (strpos($line_lower, 'warning') !== false ||
                  strpos($line_lower, 'warn') !== false) {
            return 'warning';
        } elseif (strpos($line_lower, 'notice') !== false ||
                  strpos($line_lower, 'debug') !== false) {
            return 'notice';
        } elseif (strpos($line_lower, 'success') !== false ||
                  strpos($line_lower, 'completed') !== false) {
            return 'success';
        } else {
            return 'info';
        }
    }
}

/**
 * Validate and sanitize WP-CLI command input
 */
function wpf_validate_command($command) {
    // SECURITY: Check for command injection attempts
    if (preg_match('/[;&|`$()<>]/', $command)) {
        return false;
    }

    // SECURITY: Limit command length
    if (strlen($command) > 1000) {
        return false;
    }

    // SECURITY: Only allow specific wp commands
    $allowed_commands = [
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
    ];

    $command_start = strtolower(substr(trim($command), 0, 20));
    foreach ($allowed_commands as $allowed) {
        if (strpos($command_start, $allowed) === 0) {
            return true;
        }
    }

    return false;
}

// Simple rate limiter to prevent abuse
function wpf_check_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'wpf_rate_limit_' . md5($ip);
    $now = time();

    // Get current rate limit data
    $data = get_transient($key);
    if (!$data) {
        $data = ['count' => 0, 'reset' => $now + 60]; // 60 second window
    }

    // Reset if window expired
    if ($now > $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + 60];
    }

    // Check rate limit (max 10 requests per minute)
    if ($data['count'] >= 10) {
        return false;
    }

    // Increment counter
    $data['count']++;
    set_transient($key, $data, 60);

    return true;
}

function wpf_run_command_sse($request) {
    // SECURITY: Rate limiting
    if (!wpf_check_rate_limit()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Rate limit exceeded']);
        return;
    }

    $params = $request->get_json_params();
    $command = $params['command'] ?? '';

    // SECURITY: Validate command input
    if (empty($command) || !is_string($command) || !wpf_validate_command($command)) {
        header('Content-Type: application/json');
        return new WP_Error('empty_command', 'No command provided', ['status' => 400]);
    }

    // Set headers for SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    @ob_end_clean();
    @flush();

    try {
        $runner = new WPFCommandRunner();
        $runner->execute_command($command);
    } catch (Exception $e) {
        // Emit error for PHP exceptions
        $event = [
            'type' => 'command_error',
            'timestamp' => microtime(true),
            'data' => [
                'error' => 'php_exception',
                'message' => $e->getMessage(),
                'command' => $command
            ]
        ];
        echo "event: command_error\n";
        echo "data: " . json_encode($event) . "\n\n";
        @ob_flush(); @flush();
    }

    exit;
}
