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

    public function execute_command($command) {
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

        $process = proc_open("wp $command 2>&1", $descriptorspec, $pipes, ABSPATH);
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

function wpf_run_command_sse($request) {
    $params = $request->get_json_params();
    $command = sanitize_text_field($params['command'] ?? '');

    if (empty($command)) {
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
