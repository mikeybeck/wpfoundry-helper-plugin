<?php
/*
Plugin Name: WP Foundry Helper
Description: Provides REST + SSE streaming for WP-CLI commands.
Version: 2.2
Author: Mikey
*/

add_action('rest_api_init', function () {
    // POST endpoint to queue a command
    register_rest_route('wpfoundry/v1', '/command', [
        'methods' => 'POST',
        'callback' => 'wpf_run_command',
        'permission_callback' => function () {
            return current_user_can('administrator');
        },
    ]);

    // GET endpoint for SSE stream
    register_rest_route('wpfoundry/v1', '/stream', [
        'methods' => 'GET',
        'callback' => 'wpf_sse_stream',
        'permission_callback' => function () {
            return current_user_can('administrator');
        },
    ]);
});

// Temporary store for commands (could be replaced with DB/queue)
function wpf_command_queue() {
    static $queue = [];
    return $queue;
}

// POST /command - queue a command
function wpf_run_command($request) {
    $params = $request->get_json_params();
    $command = sanitize_text_field($params['command'] ?? '');
    if (empty($command)) {
        return new WP_Error('empty_command', 'No command provided', ['status' => 400]);
    }

    // Add to temporary queue
    $queue = &wpf_command_queue();
    $queue[] = $command;

    return ['success' => true, 'message' => 'Command queued', 'command' => $command];
}

// GET /stream - SSE endpoint
function wpf_sse_stream() {
    if (!is_user_logged_in()) {
        return new WP_Error('forbidden', 'You must be logged in', ['status' => 403]);
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    @ob_end_clean();
    @flush();

    $queue = &wpf_command_queue();

    while (true) {
        if (!empty($queue)) {
            $cmd = array_shift($queue);

            // Execute WP-CLI command
            $output = [];
            $return_var = 0;
            exec("wp {$cmd} 2>&1", $output, $return_var);

            $data = json_encode([
                'command' => $cmd,
                'exit_code' => $return_var,
                'output' => implode("\n", $output)
            ]);

            // Send SSE event
            echo "event: command_output\n";
            echo "data: {$data}\n\n";
            @ob_flush();
            @flush();
        }

        // Avoid busy loop
        sleep(1);
    }

    exit;
}
