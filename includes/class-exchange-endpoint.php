<?php
/**
 * Exchange Endpoint
 *
 * HTTP эндпоинт для обмена данными с 1С по протоколу CommerceML
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Exchange Endpoint class
 */
class WC1C_Exchange_Endpoint {

    /**
     * Exchange URL slug
     */
    const ENDPOINT_SLUG = '1c-exchange';

    /**
     * Session file extension
     */
    private string $session_file = '';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_request']);
    }

    /**
     * Add rewrite rules
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . self::ENDPOINT_SLUG . '/?$',
            'index.php?wc1c_exchange=1',
            'top'
        );
    }

    /**
     * Add query vars
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'wc1c_exchange';
        return $vars;
    }

    /**
     * Handle exchange request
     */
    public function handle_request(): void {
        if (!get_query_var('wc1c_exchange')) {
            return;
        }

        // Disable caching
        nocache_headers();

        // Check if plugin is enabled
        if ('yes' !== get_option('wc1c_enabled', 'yes')) {
            $this->send_error(__('Exchange is disabled', 'wc-1c-integration'));
        }

        // Authenticate
        if (!$this->authenticate()) {
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="1C Exchange"');
            $this->send_error(__('Authentication required', 'wc-1c-integration'));
        }

        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : '';

        WC1C_Logger::log("Exchange request: type={$type}, mode={$mode}", 'info');

        try {
            switch ($type) {
                case 'catalog':
                    $this->handle_catalog($mode);
                    break;
                case 'sale':
                    $this->handle_sale($mode);
                    break;
                default:
                    $this->send_error(__('Unknown exchange type', 'wc-1c-integration'));
            }
        } catch (Exception $e) {
            WC1C_Logger::log('Exchange error: ' . $e->getMessage(), 'error');
            $this->send_error($e->getMessage());
        }

        exit;
    }

    /**
     * Authenticate request
     */
    private function authenticate(): bool {
        $username = get_option('wc1c_username', '');
        $password = get_option('wc1c_password', '');

        // If no credentials set, allow access (not recommended for production)
        if (empty($username) && empty($password)) {
            return true;
        }

        // Get credentials from request
        $auth_user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
        $auth_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

        // Try to get from Authorization header
        if (empty($auth_user) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($auth, 'Basic ') === 0) {
                $decoded = base64_decode(substr($auth, 6));
                list($auth_user, $auth_pass) = explode(':', $decoded, 2);
            }
        }

        return $auth_user === $username && $auth_pass === $password;
    }

    /**
     * Handle catalog exchange
     */
    private function handle_catalog(string $mode): void {
        switch ($mode) {
            case 'checkauth':
                $this->check_auth();
                break;
            case 'init':
                $this->init_catalog();
                break;
            case 'file':
                $this->receive_file();
                break;
            case 'import':
                $this->import_catalog();
                break;
            default:
                $this->send_error(__('Unknown catalog mode', 'wc-1c-integration'));
        }
    }

    /**
     * Handle sale (orders) exchange
     */
    private function handle_sale(string $mode): void {
        switch ($mode) {
            case 'checkauth':
                $this->check_auth();
                break;
            case 'init':
                $this->init_sale();
                break;
            case 'query':
                $this->export_orders();
                break;
            case 'success':
                $this->mark_orders_success();
                break;
            case 'file':
                $this->receive_order_file();
                break;
            default:
                $this->send_error(__('Unknown sale mode', 'wc-1c-integration'));
        }
    }

    /**
     * Check authentication
     */
    private function check_auth(): void {
        // Start session
        $session_id = md5(uniqid(mt_rand(), true));
        $upload_dir = wp_upload_dir();
        $this->session_file = $upload_dir['basedir'] . '/wc-1c-exchange/session_' . $session_id;

        // Create session file
        file_put_contents($this->session_file, time());

        // Send success response
        echo "success\n";
        echo "PHPSESSID\n";
        echo $session_id;
    }

    /**
     * Initialize catalog exchange
     */
    private function init_catalog(): void {
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';

        // Clean old files
        $this->clean_exchange_dir($exchange_dir);

        // Send parameters
        echo "zip=no\n";
        echo "file_limit=" . $this->get_file_limit() . "\n";
    }

    /**
     * Initialize sale exchange
     */
    private function init_sale(): void {
        echo "zip=no\n";
        echo "file_limit=" . $this->get_file_limit() . "\n";
    }

    /**
     * Receive file from 1C
     */
    private function receive_file(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        if (empty($filename)) {
            $this->send_error(__('Filename is required', 'wc-1c-integration'));
        }

        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        
        // Ensure directory exists
        if (!file_exists($exchange_dir)) {
            wp_mkdir_p($exchange_dir);
        }

        // Handle subdirectories in filename
        $file_path = $exchange_dir . '/' . $filename;
        $file_dir = dirname($file_path);
        
        if (!file_exists($file_dir)) {
            wp_mkdir_p($file_dir);
        }

        // Get raw POST data
        $data = file_get_contents('php://input');
        
        if (empty($data)) {
            $this->send_error(__('No data received', 'wc-1c-integration'));
        }

        // Append to file (for chunked uploads)
        $result = file_put_contents($file_path, $data, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            $this->send_error(__('Failed to write file', 'wc-1c-integration'));
        }

        WC1C_Logger::log("Received file: {$filename}, size: " . strlen($data), 'info');

        echo "success\n";
    }

    /**
     * Import catalog from received files
     */
    private function import_catalog(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';

        // Determine file to process
        if (empty($filename)) {
            // Find import.xml or offers.xml
            if (file_exists($exchange_dir . '/import.xml')) {
                $filename = 'import.xml';
            } elseif (file_exists($exchange_dir . '/offers.xml')) {
                $filename = 'offers.xml';
            } else {
                $this->send_error(__('No files to import', 'wc-1c-integration'));
            }
        }

        $file_path = $exchange_dir . '/' . $filename;
        
        if (!file_exists($file_path)) {
            $this->send_error(sprintf(__('File not found: %s', 'wc-1c-integration'), $filename));
        }

        // Increase limits for import
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $parser = wc1c()->parser;
        $product_sync = wc1c()->product_sync;

        try {
            if (strpos($filename, 'import') !== false) {
                // Import catalog structure
                $data = $parser->parse_import($file_path);
                
                // Sync categories
                if (!empty($data['categories'])) {
                    $cat_results = $product_sync->sync_categories($data['categories']);
                    WC1C_Logger::log("Categories synced: " . json_encode($cat_results), 'info');
                }

                // Sync products
                if (!empty($data['products'])) {
                    $prod_results = $product_sync->sync_products($data['products']);
                    WC1C_Logger::log("Products synced: " . json_encode($prod_results), 'info');
                }

                echo "success\n";
                
            } elseif (strpos($filename, 'offers') !== false) {
                // Import prices and stock
                $data = $parser->parse_offers($file_path);
                
                if (!empty($data['offers'])) {
                    $results = $product_sync->update_offers($data['offers']);
                    WC1C_Logger::log("Offers updated: " . json_encode($results), 'info');
                }

                echo "success\n";
                
            } else {
                // Try to determine type from content
                $content = file_get_contents($file_path, false, null, 0, 1000);
                
                if (strpos($content, 'Каталог') !== false || strpos($content, 'Классификатор') !== false) {
                    $data = $parser->parse_import($file_path);
                    
                    if (!empty($data['categories'])) {
                        $product_sync->sync_categories($data['categories']);
                    }
                    if (!empty($data['products'])) {
                        $product_sync->sync_products($data['products']);
                    }
                    
                } elseif (strpos($content, 'ПакетПредложений') !== false) {
                    $data = $parser->parse_offers($file_path);
                    
                    if (!empty($data['offers'])) {
                        $product_sync->update_offers($data['offers']);
                    }
                }

                echo "success\n";
            }

            // Log sync completion
            $this->log_sync('catalog_import', 'incoming', 'success');

        } catch (Exception $e) {
            $this->log_sync('catalog_import', 'incoming', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Export orders to 1C
     */
    private function export_orders(): void {
        $order_sync = wc1c()->order_sync;

        // Get orders XML
        $xml = $order_sync->export_orders_xml();

        // Set headers
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Length: ' . strlen($xml));

        echo $xml;

        $this->log_sync('orders_export', 'outgoing', 'success');
    }

    /**
     * Mark orders as successfully exported
     */
    private function mark_orders_success(): void {
        // Parse the incoming success confirmation
        $data = file_get_contents('php://input');
        
        // 1C sends order IDs that were successfully processed
        // For simplicity, we'll mark all pending orders as exported
        $order_sync = wc1c()->order_sync;
        $orders = $order_sync->get_orders_for_export();
        
        $order_ids = array_map(function($order) {
            // Extract WC order ID from the prepared data
            return $order['number'];
        }, $orders);

        if (!empty($order_ids)) {
            $order_sync->mark_orders_exported($order_ids);
        }

        echo "success\n";
    }

    /**
     * Receive order updates from 1C
     */
    private function receive_order_file(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        $file_path = $exchange_dir . '/' . $filename;

        // Get raw POST data
        $data = file_get_contents('php://input');
        
        if (empty($data)) {
            $this->send_error(__('No data received', 'wc-1c-integration'));
        }

        file_put_contents($file_path, $data, FILE_APPEND | LOCK_EX);

        // Process order updates if complete file
        if (strpos($filename, 'orders') !== false && file_exists($file_path)) {
            $this->process_order_updates($file_path);
        }

        echo "success\n";
    }

    /**
     * Process order updates from 1C
     */
    private function process_order_updates(string $file_path): void {
        // Parse the XML
        $xml = simplexml_load_file($file_path);
        
        if (!$xml) {
            return;
        }

        $updates = [];
        
        if (isset($xml->Документ)) {
            foreach ($xml->Документ as $doc) {
                $update = [
                    'id' => (string)$doc->Ид,
                    'number' => (string)$doc->Номер,
                ];

                // Extract status from requisites
                if (isset($doc->ЗначенияРеквизитов->ЗначениеРеквизита)) {
                    foreach ($doc->ЗначенияРеквизитов->ЗначениеРеквизита as $req) {
                        $name = (string)$req->Наименование;
                        $value = (string)$req->Значение;

                        switch ($name) {
                            case 'Статус заказа':
                                $update['status'] = $value;
                                break;
                            case 'Номер отправления':
                                $update['tracking_number'] = $value;
                                break;
                            case 'Номер документа 1С':
                                $update['1c_document_number'] = $value;
                                break;
                        }
                    }
                }

                $updates[] = $update;
            }
        }

        if (!empty($updates)) {
            $order_sync = wc1c()->order_sync;
            $results = $order_sync->process_order_updates($updates);
            WC1C_Logger::log("Order updates processed: " . json_encode($results), 'info');
        }
    }

    /**
     * Clean exchange directory
     */
    private function clean_exchange_dir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        $now = time();
        $max_age = 3600; // 1 hour

        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) > $max_age) {
                    @unlink($file);
                }
            } elseif (is_dir($file)) {
                // Recursively clean subdirectories
                $this->clean_exchange_dir($file);
            }
        }
    }

    /**
     * Get file size limit for uploads
     */
    private function get_file_limit(): int {
        $upload_max = $this->parse_size(ini_get('upload_max_filesize'));
        $post_max = $this->parse_size(ini_get('post_max_size'));
        $memory = $this->parse_size(ini_get('memory_limit'));

        $limit = min($upload_max, $post_max, $memory / 4);
        
        // Return at least 1MB, max 100MB
        return max(1024 * 1024, min($limit, 100 * 1024 * 1024));
    }

    /**
     * Parse size string to bytes
     */
    private function parse_size(string $size): int {
        $unit = strtolower(substr($size, -1));
        $value = (int)$size;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }

    /**
     * Log sync operation
     */
    private function log_sync(string $type, string $direction, string $status, string $message = ''): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'wc1c_sync_log',
            [
                'sync_type' => $type,
                'direction' => $direction,
                'status' => $status,
                'message' => $message,
                'started_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Send error response
     */
    private function send_error(string $message): void {
        echo "failure\n";
        echo $message;
        exit;
    }

    /**
     * Get exchange URL
     */
    public static function get_exchange_url(): string {
        return home_url('/' . self::ENDPOINT_SLUG . '/');
    }
}
