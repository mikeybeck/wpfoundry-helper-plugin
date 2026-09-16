<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are API/log errors, not admin HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions -- zip/backup/WP-CLI temp files and pipes are not WP_Filesystem operations.

class WPFoundry_Command_Runner {

    private function emit_event($type, $data = []) {
        $event = [
            'type' => $type,
            'timestamp' => microtime(true),
            'data' => $data
        ];
        echo 'event: ' . esc_html($type) . "\n";
        echo 'data: ' . wp_json_encode($event) . "\n\n";
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
            case 'backup-content':
            case 'backup-wp-content':
                $this->wpfoundry_backup_wp_content($args);
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
            case 'plugin-install-from-upload':
                $this->wpfoundry_plugin_theme_install_from_upload($args, 'plugin');
                break;
            case 'theme-install-from-upload':
                $this->wpfoundry_plugin_theme_install_from_upload($args, 'theme');
                break;
            case 'download-site-file':
                $this->wpfoundry_download_site_file($args);
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

        $version = $this->wpfoundry_get_this_plugin_version();

        $this->emit_event('command_data', [
            'data' => [['status' => 'success', 'version' => $version]],
            'line_number' => 1,
            'raw_line' => wp_json_encode(['status' => 'success', 'version' => $version])
        ]);

        $this->emit_event('command_complete', [
            'success' => true,
            'exit_code' => 0,
            'end_time' => microtime(true)
        ]);
    }

    /**
     * Create a short-lived download token for an allowlisted site file (copy, so original is kept).
     * Usage: wpfoundry download-site-file wp-config.php
     *        wpfoundry download-site-file wp-content/debug.log
     */
    private function wpfoundry_download_site_file($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry download-site-file ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        try {
            $requested = isset($args[0]) ? trim((string) $args[0]) : '';
            if ($requested === '') {
                throw new Exception('Missing file path argument');
            }

            $requested = ltrim(str_replace('\\', '/', $requested), '/');
            $uploads_basedir = function_exists('wpfoundry_uploads_basedir') ? wpfoundry_uploads_basedir() : untrailingslashit(wp_upload_dir()['basedir']);
            $allowed = [
                'wp-config.php' => ABSPATH . 'wp-config.php',
                'wp-content/debug.log' => WP_CONTENT_DIR . '/debug.log',
                'wp-content/error.log' => WP_CONTENT_DIR . '/error.log',
                'wp-content/uploads/debug.log' => $uploads_basedir . '/debug.log',
                'wp-content/uploads/error.log' => $uploads_basedir . '/error.log',
                'debug.log' => WP_CONTENT_DIR . '/debug.log',
                'error.log' => WP_CONTENT_DIR . '/error.log',
            ];

            if (!isset($allowed[$requested])) {
                throw new Exception('File path is not allowlisted: ' . $requested);
            }

            $source = $allowed[$requested];
            if (!file_exists($source) || !is_readable($source) || !is_file($source)) {
                throw new Exception('File not found or not readable: ' . $requested);
            }

            $token = bin2hex(random_bytes(16));
            $filename = basename($source);
            $is_php = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'php';
            if ($is_php) {
                $token_path = $source;
                $delete_after = false;
            } else {
                $token_path = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '-' . $filename;
                if (!@copy($source, $token_path)) {
                    throw new Exception('Failed to stage file for download');
                }
                $delete_after = true;
            }

            $expires_at = time() + 120;
            wpfoundry_store_download_token($token, [
                'path' => $token_path,
                'filename' => $filename,
                'created_at' => time(),
                'expires_at' => $expires_at,
                'delete_after' => $delete_after,
            ], 120);

            $payload = [
                'status' => 'success',
                'token' => $token,
                'filename' => $filename,
                'size' => filesize($token_path),
                'requested' => $requested,
            ];

            $this->emit_event('command_data', [
                'data' => [$payload],
                'line_number' => 1,
                'raw_line' => wp_json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'success' => true,
                'exit_code' => 0,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'download_site_file_failed',
                'message' => $e->getMessage(),
            ]);
            $this->emit_event('command_complete', [
                'success' => false,
                'exit_code' => 1,
                'end_time' => microtime(true)
            ]);
        }
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
            'raw_line' => wp_json_encode(['version' => $version])
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
        $data = get_plugin_data(WPFOUNDRY_HELPER_FILE, false, false);
        return isset($data['Version']) ? $data['Version'] : 'unknown';
    }

    private function wpfoundry_get_this_plugin_slug_dir() {
        // plugin_basename(WPFOUNDRY_HELPER_FILE) -> folder/main-file.php
        $base = plugin_basename(WPFOUNDRY_HELPER_FILE);
        $dir = dirname($base);
        return $dir === '.' ? '' : $dir;
    }


    private function wpfoundry_find_helper_main_file($extract_base) {
        foreach (array('foundry-helper.php', 'wpfoundry-helper.php') as $filename) {
            $found = $this->wpfoundry_find_file_recursive($extract_base, $filename, 6);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    private function wpfoundry_is_allowed_helper_zip_url($zip_url) {
        $parts = wp_parse_url($zip_url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        $host = strtolower($parts['host']);
        return in_array($host, ['github.com', 'codeload.github.com'], true);
    }

    private function wpfoundry_zip_entry_is_safe($entry_name, $extract_real) {
        if ($entry_name === '' || strpos($entry_name, "\0") !== false) {
            return false;
        }
        $name = str_replace('\\', '/', $entry_name);
        if ($name[0] === '/' || preg_match('#^[a-zA-Z]:/#', $name)) {
            return false;
        }
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        $target = $extract_real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $extract_prefix = rtrim($extract_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        // Resolve .. and . without requiring the path to exist yet.
        $normalized = $this->wpfoundry_normalize_filesystem_path($target);
        if ($normalized === $extract_real) {
            return true;
        }
        return strpos($normalized . DIRECTORY_SEPARATOR, $extract_prefix) === 0;
    }

    private function wpfoundry_normalize_filesystem_path($path) {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        $absolute = isset($path[0]) && $path[0] === '/';
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!empty($parts)) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        $normalized = implode(DIRECTORY_SEPARATOR, $parts);
        return $absolute ? (DIRECTORY_SEPARATOR . $normalized) : $normalized;
    }

    private function wpfoundry_download_and_extract_zip($zip_url) {
        if (!$this->wpfoundry_is_allowed_helper_zip_url($zip_url)) {
            throw new Exception('Zip URL must be HTTPS from github.com or codeload.github.com');
        }

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
            wp_delete_file($tmp);
            throw new Exception('Failed to create temp dir for extraction');
        }

        $extract_real = realpath($extract_base);
        if ($extract_real === false) {
            wp_delete_file($tmp);
            $this->wpfoundry_cleanup_dir_best_effort($extract_base);
            throw new Exception('Failed to resolve extract directory');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($tmp);
        if ($opened !== true) {
            wp_delete_file($tmp);
            $this->wpfoundry_cleanup_dir_best_effort($extract_base);
            throw new Exception('Unzip failed: unable to open zip (code ' . $opened . ')');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry_name = $zip->getNameIndex($i);
            if ($entry_name === false) {
                continue;
            }
            if (!$this->wpfoundry_zip_entry_is_safe($entry_name, $extract_real)) {
                $zip->close();
                wp_delete_file($tmp);
                $this->wpfoundry_cleanup_dir_best_effort($extract_base);
                throw new Exception('Zip path traversal detected: ' . $entry_name);
            }
        }

        $ok = $zip->extractTo($extract_base);
        $zip->close();
        wp_delete_file($tmp);

        if (!$ok) {
            $this->wpfoundry_cleanup_dir_best_effort($extract_base);
            throw new Exception('Unzip failed: extractTo() returned false');
        }

        return $extract_base;
    }

    /**
     * Replace the plugin directory without deleting first: stage new copy, rename
     * existing aside, move staged into place, then remove the backup.
     */
    private function wpfoundry_replace_plugin_dir($source_dir, $dest_dir) {
        $dest_dir = rtrim($dest_dir, '/\\');
        $staging = $dest_dir . '.wpf-new-' . uniqid('', true);
        $backup = $dest_dir . '.wpf-old-' . uniqid('', true);

        try {
            $this->wpfoundry_copy_dir_recursive($source_dir, $staging);

            if (file_exists($dest_dir)) {
                if (!@rename($dest_dir, $backup)) {
                    throw new Exception('Failed to move existing plugin directory aside');
                }
            }

            if (!@rename($staging, $dest_dir)) {
                if (file_exists($backup) && !file_exists($dest_dir)) {
                    @rename($backup, $dest_dir);
                }
                throw new Exception('Failed to move new plugin directory into place');
            }

            $this->wpfoundry_cleanup_dir_best_effort($backup);
        } catch (Exception $e) {
            $this->wpfoundry_cleanup_dir_best_effort($staging);
            throw $e;
        }
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
            wp_delete_file($dir);
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

        $payload = wpfoundry_helper_version_payload();
        if ($payload['version'] === '' || $payload['version'] === 'unknown') {
            $payload['version'] = $this->wpfoundry_get_this_plugin_version();
        }

        $this->emit_event('command_data', [
            'data' => $payload,
            'line_number' => 1,
            'raw_line' => wp_json_encode($payload)
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

        if (function_exists('wpfoundry_is_directory_edition') && wpfoundry_is_directory_edition()) {
            $this->emit_event('command_error', [
                'error' => 'directory_updates',
                'message' => 'GitHub helper updates are disabled for the WordPress.org pairing plugin. Install the full helper from GitHub instead.',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        $zip_url = isset($args[0]) ? trim((string) $args[0]) : '';
        if (!$zip_url || !filter_var($zip_url, FILTER_VALIDATE_URL) || !$this->wpfoundry_is_allowed_helper_zip_url($zip_url)) {
            $this->emit_event('command_error', [
                'error' => 'missing_zip_url',
                'message' => 'Missing or invalid zip URL argument (HTTPS github.com / codeload.github.com only)',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        try {
            $extract_base = $this->wpfoundry_download_and_extract_zip($zip_url);
            $main_file = $this->wpfoundry_find_helper_main_file($extract_base);
            if (!$main_file) {
                $this->wpfoundry_cleanup_dir_best_effort($extract_base);
                throw new Exception('Could not locate foundry-helper.php in extracted archive');
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
                'raw_line' => wp_json_encode($payload)
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

        if (function_exists('wpfoundry_is_directory_edition') && wpfoundry_is_directory_edition()) {
            $this->emit_event('command_error', [
                'error' => 'directory_updates',
                'message' => 'GitHub helper updates are disabled for the WordPress.org pairing plugin. Install the full helper from GitHub instead.',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        $zip_url = isset($args[0]) ? trim((string) $args[0]) : '';
        if (!$zip_url || !filter_var($zip_url, FILTER_VALIDATE_URL) || !$this->wpfoundry_is_allowed_helper_zip_url($zip_url)) {
            $this->emit_event('command_error', [
                'error' => 'missing_zip_url',
                'message' => 'Missing or invalid zip URL argument (HTTPS github.com / codeload.github.com only)',
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
            $main_file = $this->wpfoundry_find_helper_main_file($extract_base);
            if (!$main_file) {
                $this->wpfoundry_cleanup_dir_best_effort($extract_base);
                throw new Exception('Could not locate foundry-helper.php in extracted archive');
            }

            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $data = get_plugin_data($main_file, false, false);
            $latest_version = isset($data['Version']) ? $data['Version'] : 'unknown';

            $source_dir = dirname($main_file);
            $dest_dir = trailingslashit(WP_PLUGIN_DIR) . $slug_dir;

            // Stage + rename replace (avoids bricking if copy fails mid-way)
            $this->wpfoundry_replace_plugin_dir($source_dir, $dest_dir);

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
                'raw_line' => wp_json_encode($payload)
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
                'raw_line' => wp_json_encode($result)
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

    /**
     * Install plugin or theme from uploaded zip via WP-CLI.
     * Token is from wpfoundry_upload_* transient (set by upload endpoint).
     */
    private function wpfoundry_plugin_theme_install_from_upload($args, $type) {
        $subcmd = $type === 'plugin' ? 'plugin-install-from-upload' : 'theme-install-from-upload';
        $this->emit_event('command_start', [
            'command' => 'wpfoundry ' . $subcmd . ' ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        $token = isset($args[0]) ? trim((string) $args[0]) : '';
        if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            $this->emit_event('command_error', [
                'error' => 'invalid_token',
                'message' => 'Missing or invalid upload token',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        $data = wpfoundry_upload_get_token($token);
        if (!is_array($data) || empty($data['path']) || !file_exists($data['path'])) {
            $this->emit_event('command_error', [
                'error' => 'token_not_found',
                'message' => 'Upload token not found or file no longer exists',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        $path = $data['path'];
        $wp_cmd = $type === 'plugin' ? 'plugin install' : 'theme install';
        $full_cmd = "wp {$wp_cmd} " . escapeshellarg($path) . ' --force';
        $env = wpfoundry_build_cli_env();

        // Only open the stdout pipe; stderr is merged in via "2>&1" so an
        // unread stderr pipe cannot deadlock the child.
        $descriptorspec = [1 => ['pipe', 'w']];
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WP-CLI must run as a child process after HMAC + allowlist checks.
        $process = proc_open($full_cmd . ' 2>&1', $descriptorspec, $pipes, ABSPATH, $env);
        if (!is_resource($process)) {
            $this->emit_event('command_error', [
                'error' => 'proc_open_failed',
                'message' => 'Failed to run WP-CLI install command',
                'exit_code' => 1,
                'status' => 'error'
            ]);
            return;
        }

        // stderr is merged into stdout via "2>&1" above, so we only have
        // $pipes[1] to read.
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit_code = proc_close($process);

        if ($exit_code !== 0) {
            $msg = trim($output ?: 'Installation failed');
            $this->emit_event('command_error', [
                'error' => 'install_failed',
                'message' => $msg,
                'exit_code' => $exit_code,
                'status' => 'error'
            ]);
            return;
        }

        $this->emit_event('command_data', [
            'data' => [['status' => 'success', 'type' => $type, 'path' => $path]],
            'line_number' => 1,
            'raw_line' => wp_json_encode(['status' => 'success', 'type' => $type])
        ]);
        wpfoundry_upload_delete_token($token);
        $this->emit_event('command_complete', [
            'exit_code' => 0,
            'status' => 'success',
            'total_lines' => 1,
            'end_time' => microtime(true)
        ]);
    }

    private function parse_list_files_args($args) {
        $options = [
            'type' => 'all',
            'path' => '',
            'recursive' => true,
            'max_depth' => 3,
            'include' => [],
            'exclude_patterns' => []
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
                            $options['exclude_patterns'] = explode(',', $value);
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
        $uploads_basedir = function_exists('wpfoundry_uploads_basedir') ? wpfoundry_uploads_basedir() : untrailingslashit(wp_upload_dir()['basedir']);
        $base_paths = [
            'core' => ABSPATH,
            'plugins' => WP_PLUGIN_DIR,
            'themes' => get_theme_root(),
            'uploads' => $uploads_basedir,
            'content' => WP_CONTENT_DIR
        ];

        if (!empty($options['path'])) {
            $scan_path = ABSPATH . ltrim($options['path'], '/');
        } else {
            $scan_path = isset($base_paths[$options['type']]) ? $base_paths[$options['type']] : ABSPATH;
        }

        $scan_path = $this->wpfoundry_confine_path_under_abspath($scan_path);

        return $this->scan_directory($scan_path, $scan_path, $options, 0);
    }

    /**
     * Resolve $path and ensure it stays under the WordPress ABSPATH tree.
     */
    private function wpfoundry_confine_path_under_abspath($path) {
        $abs = realpath(ABSPATH);
        if ($abs === false) {
            throw new Exception('Could not resolve WordPress root');
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            throw new Exception("Directory does not exist: $path");
        }

        if ($resolved === $abs) {
            return $resolved;
        }

        $abs_prefix = rtrim($abs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($resolved . DIRECTORY_SEPARATOR, $abs_prefix) !== 0) {
            throw new Exception('Path is outside the WordPress installation');
        }

        return $resolved;
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
            if ($this->matches_patterns($relative_path, $options['exclude_patterns'])) {
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
            } elseif (substr($slug, -4) !== '.php' && is_file($plugin_path . '.php')) {
                // Single-file plugin: WP-CLI returns "hello" but file is hello.php
                $source_path = $plugin_path . '.php';
            } elseif (substr($slug, -4) !== '.php' && is_file($content_dropin_path . '.php')) {
                // Single-file drop-in in wp-content (e.g. object-cache.php)
                $source_path = $content_dropin_path . '.php';
            } else {
                throw new Exception("Plugin not found: $plugin_path (and not found as single-file $plugin_path.php)");
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
                'raw_line' => wp_json_encode($payload)
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
                'raw_line' => wp_json_encode($payload)
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
            // Export DB to a temporary SQL file. stderr is merged into stdout
            // via "2>&1" so we only allocate the stdout pipe — an unread
            // stderr pipe could otherwise deadlock the child on verbose output.
            $descriptorspec = [
                1 => ['pipe', 'w'], // stdout (also receives stderr via 2>&1)
            ];

            $command_to_run = 'wp db export ' . escapeshellarg($tmp_sql);
            $env = wpfoundry_build_cli_env();

            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WP-CLI must run as a child process after HMAC + allowlist checks.
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
                'raw_line' => wp_json_encode($payload)
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
                wp_delete_file($tmp_sql);
            }
        }
    }

    private function wpfoundry_backup_wp_content($args) {
        $this->emit_event('command_start', [
            'command' => 'wpfoundry backup-content ' . implode(' ', $args),
            'wp_command' => 'wpfoundry',
            'start_time' => microtime(true)
        ]);

        // phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged -- scoped to this content-backup request; hosts often cap PHP time below a full wp-content zip.
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        // phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
        @ignore_user_abort(true);

        $include_uploads = true;
        foreach ($args as $arg) {
            $arg = strtolower(trim($arg));
            if (in_array($arg, ['--no-uploads', 'no-uploads', '--exclude-uploads', 'exclude-uploads'], true)) {
                $include_uploads = false;
            }
        }

        try {
            $start_time = microtime(true);
            $last_emit = $start_time;
            $processed = 0;

            $source_dir = WP_CONTENT_DIR;
            if (!is_dir($source_dir)) {
                throw new Exception('wp-content directory not found');
            }

            $source_dir = realpath($source_dir);
            if ($source_dir === false) {
                throw new Exception('wp-content directory not found');
            }

            $uploads_info = wp_upload_dir();
            if (!empty($uploads_info['error'])) {
                throw new Exception($uploads_info['error']);
            }
            $uploads_dir = isset($uploads_info['basedir']) ? realpath($uploads_info['basedir']) : false;

            $token = bin2hex(random_bytes(16));
            $zip_path = trailingslashit(sys_get_temp_dir()) . 'wpfoundry-dl-' . $token . '.zip';
            $root_name = basename($source_dir);

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                if ($opened !== true) {
                    throw new Exception('Failed to create zip (code ' . $opened . ')');
                }

                $zip->addEmptyDir($root_name);

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file_info) {
                    /** @var SplFileInfo $file_info */
                    if ($file_info->isLink()) {
                        continue;
                    }

                    $file_path = $file_info->getRealPath();
                    if ($file_path === false) {
                        continue;
                    }

                    if (
                        !$include_uploads
                        && $uploads_dir
                        && ($file_path === $uploads_dir || strpos($file_path, $uploads_dir . DIRECTORY_SEPARATOR) === 0)
                    ) {
                        continue;
                    }

                    $relative = ltrim(str_replace($source_dir, '', $file_path), DIRECTORY_SEPARATOR);
                    if ($relative === '') {
                        continue;
                    }

                    $zip_entry = $root_name . '/' . $relative;
                    if ($file_info->isDir()) {
                        $zip->addEmptyDir($zip_entry);
                        $processed++;
                        if (microtime(true) - $last_emit > 1) {
                            $this->emit_event('command_progress', [
                                'lines_processed' => $processed,
                                'elapsed_time' => microtime(true) - $start_time
                            ]);
                            $last_emit = microtime(true);
                        }
                        continue;
                    }

                    $zip->addFile($file_path, $zip_entry);
                    $processed++;
                    if (microtime(true) - $last_emit > 1) {
                        $this->emit_event('command_progress', [
                            'lines_processed' => $processed,
                            'elapsed_time' => microtime(true) - $start_time
                        ]);
                        $last_emit = microtime(true);
                    }
                }

                $this->emit_event('command_progress', [
                    'lines_processed' => $processed,
                    'elapsed_time' => microtime(true) - $start_time
                ]);
                $zip->close();
            } else {
                if (!class_exists('PclZip')) {
                    $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
                    if (file_exists($pclzip_path)) {
                        require_once $pclzip_path;
                    }
                }
                if (!class_exists('PclZip')) {
                    throw new Exception('Neither ZipArchive nor PclZip are available on this server');
                }

                $file_list = [];
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file_info) {
                    /** @var SplFileInfo $file_info */
                    if ($file_info->isLink() || $file_info->isDir()) {
                        continue;
                    }

                    $file_path = $file_info->getRealPath();
                    if ($file_path === false) {
                        continue;
                    }

                    if (
                        !$include_uploads
                        && $uploads_dir
                        && ($file_path === $uploads_dir || strpos($file_path, $uploads_dir . DIRECTORY_SEPARATOR) === 0)
                    ) {
                        continue;
                    }

                    $file_list[] = $file_info->getPathname();
                    $processed++;
                    if (microtime(true) - $last_emit > 1) {
                        $this->emit_event('command_progress', [
                            'lines_processed' => $processed,
                            'elapsed_time' => microtime(true) - $start_time
                        ]);
                        $last_emit = microtime(true);
                    }
                }

                $this->emit_event('command_progress', [
                    'lines_processed' => $processed,
                    'elapsed_time' => microtime(true) - $start_time
                ]);
                $archive = new PclZip($zip_path);
                $result = $archive->create(
                    $file_list,
                    PCLZIP_OPT_REMOVE_PATH, $source_dir,
                    PCLZIP_OPT_ADD_PATH, $root_name
                );
                if ($result == 0) {
                    throw new Exception('PclZip failed to add files: ' . $archive->errorInfo(true));
                }
            }

            $size = @filesize($zip_path);
            if ($size === false) {
                $size = 0;
            }

            $expires_in = 300;
            $expires_at = time() + $expires_in;
            $filename = $include_uploads ? 'wp-content-backup.zip' : 'wp-content-backup-no-uploads.zip';

            wpfoundry_store_download_token($token, [
                'path' => $zip_path,
                'filename' => $filename,
                'created_at' => time(),
                'expires_at' => $expires_at,
                'delete_after' => true,
            ], $expires_in);

            $payload = [
                'status' => 'success',
                'type' => 'content',
                'slug' => 'wp-content',
                'token' => $token,
                'filename' => $filename,
                'size' => $size,
                'expires_in' => $expires_in,
                'include_uploads' => $include_uploads,
            ];

            $this->emit_event('command_data', [
                'data' => [$payload],
                'line_number' => 1,
                'raw_line' => wp_json_encode($payload)
            ]);

            $this->emit_event('command_complete', [
                'exit_code' => 0,
                'status' => 'success',
                'total_lines' => 1,
                'end_time' => microtime(true)
            ]);
        } catch (Exception $e) {
            $this->emit_event('command_error', [
                'error' => 'backup_content_failed',
                'message' => $e->getMessage(),
                'exit_code' => 1,
                'status' => 'error'
            ]);
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

            // WordPress expects plugin/theme zips to have a root folder (slug).
            if (is_file($base_dir)) {
                // Single-file plugin/drop-in: put in slug folder.
                $zip->addFile($base_dir, $slug . '/' . basename($base_dir));
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
                    // Put plugin/theme files inside slug folder for valid WordPress zip structure.
                    if ($rel !== '') {
                        $zip->addFile($file_info->getPathname(), $slug . '/' . $rel);
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
                // Single-file plugin/drop-in: put in slug folder.
                $base_remove = dirname($base_dir);
                $result = $archive->create($base_dir, PCLZIP_OPT_REMOVE_PATH, $base_remove, PCLZIP_OPT_ADD_PATH, $slug);
                if ($result == 0) {
                    throw new Exception('PclZip failed to add file: ' . $archive->errorInfo(true));
                }
            } else {
                // Directory plugin/theme: put contents inside slug folder.
                $base_remove = rtrim($base_dir, '/\\');
                $result = $archive->create($base_dir, PCLZIP_OPT_REMOVE_PATH, $base_remove, PCLZIP_OPT_ADD_PATH, $slug);
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

        wpfoundry_store_download_token($token, [
            'path' => $zip_path,
            'filename' => $filename,
            'created_at' => time(),
            'expires_at' => $expires_at,
            'delete_after' => true,
        ], $expires_in);

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

        $start_time = microtime(true);

        // Parse command to extract structured info
        $command_parts = explode(' ', trim($command));
        $wp_command = $command_parts[0] ?? $command;

        // Emit command start event
        $this->emit_event('command_start', [
            'command' => $command,
            'wp_command' => $wp_command,
            'start_time' => $start_time
        ]);

        // Redirect the child's stderr into stdout inside the shell so we only
        // need a single pipe. Allocating a pipe for fd 2 and never reading it
        // risks deadlock if the child writes enough stderr to fill the pipe buffer.
        $descriptorspec = [
            1 => ['pipe', 'w'], // stdout (also receives stderr via 2>&1)
        ];

        // SECURITY: Per-argument shell escaping (stronger than escapeshellcmd on the whole string)
        $safe_command = wpfoundry_build_safe_shell_command($command);
        if ($safe_command === false) {
            $this->emit_event('command_error', [
                'error' => 'invalid_command',
                'message' => 'Failed to tokenize command for safe execution',
                'command' => $command
            ]);
            return;
        }

        $env = wpfoundry_build_cli_env();

        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- WP-CLI must run as a child process after HMAC + allowlist checks.
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
        $last_output_time = $start_time;

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
                        'elapsed_time' => $current_time - $start_time
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
