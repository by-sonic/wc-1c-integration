<?php
/**
 * Logger
 *
 * Логирование операций плагина
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Logger class
 */
class WC1C_Logger {

    /**
     * Log file path
     */
    private static ?string $log_file = null;

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, warning, error, debug)
     */
    public static function log(string $message, string $level = 'info'): void {
        // Check if debug mode is enabled
        if ($level === 'debug' && 'yes' !== get_option('wc1c_debug_mode', 'no')) {
            return;
        }

        // Use WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'wc-1c-integration'];
            
            switch ($level) {
                case 'error':
                    $logger->error($message, $context);
                    break;
                case 'warning':
                    $logger->warning($message, $context);
                    break;
                case 'debug':
                    $logger->debug($message, $context);
                    break;
                default:
                    $logger->info($message, $context);
            }
        }

        // Also log to custom file for easier access
        self::write_to_file($message, $level);
    }

    /**
     * Write message to log file
     */
    private static function write_to_file(string $message, string $level): void {
        if (null === self::$log_file) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/wc-1c-exchange/debug.log';
        }

        $log_dir = dirname(self::$log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $level_upper = strtoupper($level);
        $log_entry = "[{$timestamp}] [{$level_upper}] {$message}\n";

        // Rotate log if too large (> 5MB)
        if (file_exists(self::$log_file) && filesize(self::$log_file) > 5 * 1024 * 1024) {
            self::rotate_log();
        }

        error_log($log_entry, 3, self::$log_file);
    }

    /**
     * Rotate log file
     */
    private static function rotate_log(): void {
        if (file_exists(self::$log_file)) {
            $backup = self::$log_file . '.' . date('Y-m-d-H-i-s');
            rename(self::$log_file, $backup);

            // Keep only last 5 backup files
            $dir = dirname(self::$log_file);
            $files = glob($dir . '/debug.log.*');
            
            if (count($files) > 5) {
                usort($files, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                
                $to_delete = array_slice($files, 0, count($files) - 5);
                foreach ($to_delete as $file) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Get log contents
     *
     * @param int $lines Number of lines to return
     * @return string Log contents
     */
    public static function get_log(int $lines = 100): string {
        if (null === self::$log_file) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/wc-1c-exchange/debug.log';
        }

        if (!file_exists(self::$log_file)) {
            return '';
        }

        $file = new SplFileObject(self::$log_file);
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();

        $start = max(0, $total_lines - $lines);
        $output = [];

        $file->seek($start);
        while (!$file->eof()) {
            $line = $file->fgets();
            if (!empty(trim($line))) {
                $output[] = $line;
            }
        }

        return implode('', $output);
    }

    /**
     * Clear log file
     */
    public static function clear_log(): void {
        if (null === self::$log_file) {
            $upload_dir = wp_upload_dir();
            self::$log_file = $upload_dir['basedir'] . '/wc-1c-exchange/debug.log';
        }

        if (file_exists(self::$log_file)) {
            file_put_contents(self::$log_file, '');
        }
    }

    /**
     * Log sync statistics
     */
    public static function log_sync_stats(string $operation, array $stats): void {
        $parts = [];
        foreach ($stats as $key => $value) {
            if (is_array($value)) {
                $value = count($value);
            }
            $parts[] = "{$key}: {$value}";
        }

        self::log("{$operation} completed - " . implode(', ', $parts), 'info');
    }
}
