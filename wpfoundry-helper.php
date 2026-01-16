<?php
/*
Plugin Name: WP Foundry Helper
Description: Execute WP-CLI commands via REST with structured real-time streaming (SSE).
Version: 3.8
Author: Mikey
*/

add_action('rest_api_init', function () {
    register_rest_route('wpfoundry/v1', '/run', [
        'methods' => 'POST',
        'callback' => 'wpf_run_command_sse',
        'permission_callback' => function () {
            // Use a capability, not a role name.
            return current_user_can('manage_options');
        },
    ]);

    // Download a generated backup file by token (short-lived).
    register_rest_route('wpfoundry/v1', '/download', [
        'methods' => 'GET',
        'callback' => 'wpf_download_file',
        'permission_callback' => function () {
            // Use a capability, not a role name.
            return current_user_can('manage_options');
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
            case 'backup-plugin':
                $this->wpfoundry_backup_plugin($args);
                break;
            case 'backup-theme':
                $this->wpfoundry_backup_theme($args);
                break;
            case 'backup-db':
            case 'backup-database':
                $this->wpfoundry_backup_db($args);
                break;
            case 'core-version':
                $this->execute_core_version_command();
                break;
            case 'helper-version':
                $this->wpfoundry_helper_version();
                break;
            case 'helper-latest':
                $this->wpfoundry_helper_latest($args);
                break;
            case 'helper-update':
                $this->wpfoundry_helper_update($args);
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

    private function wpfoundry_get_this_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data(__FILE__, false, false);
        return isset($data['Version']) ? $data['Version'] : 'unknown';
    }

    private function wpfoundry_get_this_plugin_slug_dir() {
        // plugin_basename(__FILE__) -> "wpfoundry-helper/wpfoundry-helper.php"
        $base = plugin_basename(__FILE__);
        $dir = dirname($base);
        return $dir === '.' ? '' : $dir;
    }

    private function wpfoundry_download_and_extract_zip($zip_url) {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive is not available on this server');
        }

        $tmp = download_url($zip_url, 120);
        if (is_wp_error($tmp)) {
            throw new Exception('Download failed: ' . $tmp->get_error_message());
        }

        $extract_base = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-helper-update-' . uniqid('', true);
        if (!wp_mkdir_p($extract_base)) {
            @unlink($tmp);
            throw new Exception('Failed to create temp dir for extraction');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmp);
        if ($opened !== true) {
            @unlink($tmp);
            throw new Exception('Unzip failed: unable to open zip (code ' . $opened . ')');
        }

        $ok = $zip->extractTo($extract_base);
        $zip->close();
        @unlink($tmp);

        if (!$ok) {
            throw new Exception('Unzip failed: extractTo() returned false');
        }

        return $extract_base;
    }

    private function wpfoundry_find_file_recursive($base_dir, $target_filename, $max_depth = 6, $depth = 0) {
        if ($depth > $max_depth) {
            return null;
        }

        $items = @scandir($base_dir);
        if ($items === false) {
            return null;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $base_dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $found = $this->wpfoundry_find_file_recursive($full, $target_filename, $max_depth, $depth + 1);
                if ($found) {
                    return $found;
                }
            } elseif ($item === $target_filename) {
                return $full;
            }
        }

        return null;
    }

    private function wpfoundry_cleanup_dir_best_effort($dir) {
        if (!$dir) {
            return;
        }
        $this->wpfoundry_delete_dir_recursive($dir);
    }

    private function wpfoundry_delete_dir_recursive($dir) {
        if (!file_exists($dir)) {
            return;
        }
        if (is_file($dir) || is_link($dir)) {
            @unlink($dir);
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            $this->wpfoundry_delete_dir_recursive($path);
        }
        @rmdir($dir);
    }

    private function wpfoundry_copy_dir_recursive($src, $dst) {
        if (!is_dir($src)) {
            throw new Exception('Source directory does not exist: ' . $src);
        }
        if (!wp_mkdir_p($dst)) {
            throw new Exception('Failed to create destination directory: ' . $dst);
        }
        $items = @scandir($src);
        if ($items === false) {
            throw new Exception('Failed to read source directory: ' . $src);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $src . DIRECTORY_SEPARATOR . $item;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($srcPath)) {
                $this->wpfoundry_copy_dir_recursive($srcPath, $dstPath);
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    throw new Exception('Failed to copy file: ' . $srcPath);
                }
            }
        }
    }

    private function wpfoundry_helper_version() {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry helper-version',
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $payload = [
            'status' => 'success',
            'version' => $this->wpfoundry_get_this_plugin_version(),
            'slug' => $this->wpfoundry_get_this_plugin_slug_dir(),
        ];

        $this->emit_event('command_data', [
            'data' => $payload,
            'line_number' => 1,
            'raw_line' => json_encode($payload)
        ]);

        $this->emit_event('command_complete', [
            'exit_code' => 0,
            'status' => 'success',
            'total_lines' => 1,
            'end_time' => microtime(true)
        ]);
    }

    private function wpfoundry_helper_latest($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry helper-latest ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $zip_url = isset($args[0]) ? $args[0] : '';
        if (!$zip_url) {
            $this->emit_event('command_error', [
                'error' => 'missing_zip_url',
                'message' => 'Missing zip URL argument',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        try {
            $extract_base = $this->wpfoundry_download_and_extract_zip($zip_url);
            $main_file = $this->wpfoundry_find_file_recursive($extract_base, 'wpfoundry-helper.php', 6);
            if (!$main_file) {
                $this->wpfoundry_cleanup_dir_best_effort($extract_base);
                throw new Exception('Could not locate wpfoundry-helper.php in extracted archive');
            }

            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data($main_file, false, false);
            $latest_version = isset($data['Version']) ? $data['Version'] : 'unknown';

            $this->wpfoundry_cleanup_dir_best_effort($extract_base);

            $payload = [
                'status' => 'success',
                'version' => $latest_version,
            ];

            $this->emit_event('command_data', [
                'data' => $payload,
                'line_number' => 1,
                'raw_line' => json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'helper_latest_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        }
    }

    private function wpfoundry_helper_update($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry helper-update ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $zip_url = isset($args[0]) ? $args[0] : '';
        if (!$zip_url) {
            $this->emit_event('command_error', [
                'error' => 'missing_zip_url',
                'message' => 'Missing zip URL argument',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        try {
            $previous_version = $this->wpfoundry_get_this_plugin_version();
            $slug_dir = $this->wpfoundry_get_this_plugin_slug_dir();
            if (!$slug_dir) {
                throw new Exception('Could not determine plugin directory slug');
            }

            $extract_base = $this->wpfoundry_download_and_extract_zip($zip_url);
            $main_file = $this->wpfoundry_find_file_recursive($extract_base, 'wpfoundry-helper.php', 6);
            if (!$main_file) {
                $this->wpfoundry_cleanup_dir_best_effort($extract_base);
                throw new Exception('Could not locate wpfoundry-helper.php in extracted archive');
            }

            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data($main_file, false, false);
            $latest_version = isset($data['Version']) ? $data['Version'] : 'unknown';

            $source_dir = dirname($main_file);
            $dest_dir = trailingslashit(WP_PLUGIN_DIR) . $slug_dir;

            // Replace plugin directory (no WP_Filesystem; avoids FTP credential prompts)
            $this->wpfoundry_delete_dir_recursive($dest_dir);
            $this->wpfoundry_copy_dir_recursive($source_dir, $dest_dir);

            $this->wpfoundry_cleanup_dir_best_effort($extract_base);

            $new_version = $this->wpfoundry_get_this_plugin_version();

            $payload = [
                'status' => 'success',
                'previous_version' => $previous_version,
                'latest_version' => $latest_version,
                'version' => $new_version,
                'updated' => true,
            ];

            $this->emit_event('command_data', [
                'data' => $payload,
                'line_number' => 1,
                'raw_line' => json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'helper_update_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        }
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

    private function wpfoundry_backup_plugin($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry backup-plugin ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $slug = isset($args[0]) ? trim($args[0]) : '';
        if (!$slug || !preg_match('/^[a-zA-Z0-9._-]+$/', $slug)) {
            $this->emit_event('command_error', [
                'error' => 'invalid_plugin_slug',
                'message' => 'Missing or invalid plugin slug argument',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        try {
            // Support both directory plugins and single-file plugins (and common drop-ins like advanced-cache.php).
            $plugin_path = trailingslashit(WP_PLUGIN_DIR) . $slug;
            $content_dropin_path = trailingslashit(WP_CONTENT_DIR) . $slug;

            $source_path = null;
            if (is_dir($plugin_path) || is_file($plugin_path)) {
                $source_path = $plugin_path;
            } elseif (is_file($content_dropin_path)) {
                $source_path = $content_dropin_path;
            } else {
                throw new Exception("Plugin directory does not exist: $plugin_path");
            }

            $result = $this->wpfoundry_create_backup_zip_and_token($source_path, $slug, 'plugin');

            $payload = [
                'status' => 'success',
                'type' => 'plugin',
                'slug' => $slug,
                'token' => $result['token'],
                'filename' => $result['filename'],
                'size' => $result['size'],
                'expires_in' => $result['expires_in'],
            ];

            $this->emit_event('command_data', [
                'data' => [$payload],
                'line_number' => 1,
                'raw_line' => json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'backup_plugin_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        }
    }

    private function wpfoundry_backup_theme($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry backup-theme ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $slug = isset($args[0]) ? trim($args[0]) : '';
        if (!$slug || !preg_match('/^[a-zA-Z0-9._-]+$/', $slug)) {
            $this->emit_event('command_error', [
                'error' => 'invalid_theme_slug',
                'message' => 'Missing or invalid theme slug argument',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        try {
            $theme_root = function_exists('get_theme_root') ? get_theme_root() : (WP_CONTENT_DIR . '/themes');
            $base_dir = trailingslashit($theme_root) . $slug;
            $result = $this->wpfoundry_create_backup_zip_and_token($base_dir, $slug, 'theme');

            $payload = [
                'status' => 'success',
                'type' => 'theme',
                'slug' => $slug,
                'token' => $result['token'],
                'filename' => $result['filename'],
                'size' => $result['size'],
                'expires_in' => $result['expires_in'],
            ];

            $this->emit_event('command_data', [
                'data' => [$payload],
                'line_number' => 1,
                'raw_line' => json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'backup_theme_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        }
    }

    private function wpfoundry_backup_db($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry backup-db',
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $db_name = defined('DB_NAME') ? DB_NAME : 'database';
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $db_name);
        $tmp_sql = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-db-' . uniqid('', true) . '.sql';

        try {
            // Export DB to a temporary SQL file.
            $descriptorspec = [
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];

            $command_to_run = 'wp db export ' . escapeshellarg($tmp_sql);
            $env = $_ENV;
            $env['WP_CLI_CACHE_DIR'] = sys_get_temp_dir() . '/wp-cli-cache';

            $process = proc_open($command_to_run . " 2>&1", $descriptorspec, $pipes, ABSPATH, $env);
            if (!is_resource($process)) {
                throw new Exception('Failed to start WP-CLI process for db export');
            }

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $exit_code = proc_close($process);

            if ($exit_code !== 0) {
                $msg = trim($output);
                if ($msg === '') {
                    $msg = 'Unknown error during db export';
                }
                throw new Exception('DB export failed: ' . $msg);
            }

            if (!file_exists($tmp_sql) || !is_readable($tmp_sql)) {
                throw new Exception('DB export failed: SQL file not created');
            }

            $result = $this->wpfoundry_create_backup_zip_and_token($tmp_sql, $slug, 'database');

            $payload = [
                'status' => 'success',
                'type' => 'database',
                'slug' => $slug,
                'token' => $result['token'],
                'filename' => $result['filename'],
                'size' => $result['size'],
                'expires_in' => $result['expires_in'],
            ];

            $this->emit_event('command_data', [
                'data' => [$payload],
                'line_number' => 1,
                'raw_line' => json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'backup_db_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
        } finally {
            if (isset($tmp_sql) && is_string($tmp_sql) && file_exists($tmp_sql)) {
                @unlink($tmp_sql);
            }
        }
    }

    private function wpfoundry_create_backup_zip_and_token($base_dir, $slug, $type) {
        // $base_dir can be a directory (normal plugin/theme) or a file (single-file plugin / drop-in).
        if (!file_exists($base_dir)) {
            throw new Exception(ucfirst($type) . " path does not exist: $base_dir");
        }
        if (!is_readable($base_dir)) {
            throw new Exception(ucfirst($type) . " path is not readable: $base_dir");
        }

        // Token first so we can use deterministic file names (avoids relying solely on transients).
        $token = bin2hex(random_bytes(16));
        $zip_path = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.zip';

        // Prefer ZipArchive, fall back to PclZip (bundled with WordPress) if needed.
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new Exception('Failed to create zip (code ' . $opened . ')');
            }

            if (is_file($base_dir)) {
                // Single-file plugin/drop-in: put file at zip root.
                $zip->addFile($base_dir, basename($base_dir));
            } else {
                $base_norm = rtrim(str_replace('\\', '/', $base_dir), '/');
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file_info) {
                    /** @var SplFileInfo $file_info */
                    if ($file_info->isLink() || $file_info->isDir()) {
                        continue;
                    }

                    $pathname = str_replace('\\', '/', $file_info->getPathname());
                    if (strpos($pathname, $base_norm . '/') !== 0 && $pathname !== $base_norm) {
                        continue;
                    }

                    $rel = ltrim(substr($pathname, strlen($base_norm)), '/');
                    // Put plugin/theme files at zip root (no extra top-level directory).
                    if ($rel !== '') {
                        $zip->addFile($file_info->getPathname(), $rel);
                    }
                }
            }

            $zip->close();
        } else {
            // PclZip fallback
            if (!class_exists('PclZip')) {
                // WordPress bundles it, but include just in case
                $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                if (file_exists($pclzip_path)) {
                    require_once $pclzip_path;
                }
            }
            if (!class_exists('PclZip')) {
                throw new Exception('Neither ZipArchive nor PclZip are available on this server');
            }

            $archive = new PclZip($zip_path);
            if (is_file($base_dir)) {
                // Single-file plugin/drop-in: put file at zip root.
                $base_remove = dirname($base_dir);
                $result = $archive->create($base_dir, PCLZIP_OPT_REMOVE_PATH, $base_remove);
                if ($result == 0) {
                    throw new Exception('PclZip failed to add file: ' . $archive->errorInfo(true));
                }
            } else {
                // Directory plugin/theme: put contents at zip root.
                $base_remove = rtrim($base_dir, '/\\');
                $result = $archive->create($base_dir, PCLZIP_OPT_REMOVE_PATH, $base_remove);
                if ($result == 0) {
                    throw new Exception('PclZip failed to add files: ' . $archive->errorInfo(true));
                }
            }
        }

        $size = @filesize($zip_path);
        if ($size === false) {
            $size = 0;
        }

        $expires_in = 300;
        $expires_at = time() + $expires_in;
        $filename = sprintf('%s-%s-backup.zip', $type, $slug);

        // Store token info. Prefer DB-backed transient, but also persist to a temp file
        // because some sites use non-persistent object caches for transients.
        set_transient('wpf_dl_' . $token, [
            'path' => $zip_path,
            'filename' => $filename,
            'created_at' => time(),
            'expires_at' => $expires_at,
        ], $expires_in);

        $token_file = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.json';
        @file_put_contents($token_file, json_encode([
            'path' => $zip_path,
            'filename' => $filename,
            'created_at' => time(),
            'expires_at' => $expires_at,
        ]));

        return [
            'token' => $token,
            'filename' => $filename,
            'size' => $size,
            'expires_in' => $expires_in,
        ];
    }

    public function execute_command($command) {
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
        // Don't add 'wp' prefix if command already starts with 'wp'
        $command_to_run = (strpos($command, 'wp ') === 0) ? $command : "wp $command";
        $safe_command = escapeshellcmd($command_to_run);

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

    // Normalize commands: the client often sends "db export -" (without "wp " prefix),
    // but we always execute via WP-CLI (prefixing with "wp " later). Validate the
    // normalized form so "db", "plugin", etc. are properly allowlisted.
    $normalized = trim($command);
    if (stripos($normalized, 'wp ') !== 0 && stripos($normalized, 'wpfoundry') !== 0) {
        $normalized = 'wp ' . $normalized;
    }

    $command_start = strtolower(substr($normalized, 0, 20));
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

/**
 * Download a generated backup file by token.
 * Token is created by wpfoundry backup-plugin / backup-theme and stored in a transient.
 */
function wpf_download_file($request) {
    $token = $request->get_param('token');
    if (!$token || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        return new WP_Error('invalid_token', 'Missing or invalid token', ['status' => 400]);
    }

    $data = get_transient('wpf_dl_' . $token);
    if (!$data || !is_array($data)) {
        // Fallback to temp file storage
        $token_file = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.json';
        if (file_exists($token_file)) {
            $raw = @file_get_contents($token_file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }

    // Last-resort fallback: deterministic zip file name by token.
    if (!$data || !is_array($data)) {
        $zip_guess = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.zip';
        if (file_exists($zip_guess)) {
            $data = [
                'path' => $zip_guess,
                'filename' => 'backup.zip',
                'created_at' => time(),
                'expires_at' => time() + 60,
            ];
        } else {
            return new WP_Error('token_not_found', 'Token not found or expired', ['status' => 404]);
        }
    }

    // Expiry enforcement (works for both transient + file fallback)
    if (isset($data['expires_at']) && is_numeric($data['expires_at']) && time() > intval($data['expires_at'])) {
        delete_transient('wpf_dl_' . $token);
        $token_file = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.json';
        @unlink($token_file);
        $zip_guess = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.zip';
        @unlink($zip_guess);
        return new WP_Error('token_not_found', 'Token not found or expired', ['status' => 404]);
    }

    $path = isset($data['path']) ? $data['path'] : '';
    $filename = isset($data['filename']) ? $data['filename'] : 'backup.zip';

    if (!$path || !is_string($path) || !file_exists($path)) {
        delete_transient('wpf_dl_' . $token);
        $token_file = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.json';
        @unlink($token_file);
        $zip_guess = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.zip';
        @unlink($zip_guess);
        return new WP_Error('file_not_found', 'Backup file not found', ['status' => 404]);
    }

    // Stream the file then clean up.
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        delete_transient('wpf_dl_' . $token);
        return new WP_Error('file_open_failed', 'Failed to open backup file', ['status' => 500]);
    }

    while (!feof($fh)) {
        echo fread($fh, 1024 * 1024); // 1MB chunks
        @ob_flush(); @flush();
    }
    fclose($fh);

    @unlink($path);
    delete_transient('wpf_dl_' . $token);
    $token_file = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.json';
    @unlink($token_file);

    exit;
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
    if (empty($command) || !is_string($command)) {
        header('Content-Type: application/json');
        return new WP_Error('empty_command', 'No command provided', ['status' => 400]);
    }
    if (!wpf_validate_command($command)) {
        header('Content-Type: application/json');
        return new WP_Error('invalid_command', 'Invalid or disallowed command', ['status' => 400]);
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
