<?php
/**
 * Admin Settings
 *
 * Страница настроек плагина в админке WordPress
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Admin Settings class
 */
class WC1C_Admin_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_wc1c_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_wc1c_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_wc1c_clear_log', [$this, 'ajax_clear_log']);
    }

    /**
     * Add menu page
     */
    public function add_menu_page(): void {
        add_submenu_page(
            'woocommerce',
            __('1C Integration', 'wc-1c-integration'),
            __('1C Integration', 'wc-1c-integration'),
            'manage_woocommerce',
            'wc-1c-integration',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        // General settings
        register_setting('wc1c_settings', 'wc1c_enabled');
        register_setting('wc1c_settings', 'wc1c_username');
        register_setting('wc1c_settings', 'wc1c_password');

        // Sync settings
        register_setting('wc1c_settings', 'wc1c_sync_images');
        register_setting('wc1c_settings', 'wc1c_sync_categories');
        register_setting('wc1c_settings', 'wc1c_sync_attributes');
        register_setting('wc1c_settings', 'wc1c_sync_stock');
        register_setting('wc1c_settings', 'wc1c_sync_prices');

        // Price and warehouse settings
        register_setting('wc1c_settings', 'wc1c_price_type');
        register_setting('wc1c_settings', 'wc1c_warehouse');

        // Order settings
        register_setting('wc1c_settings', 'wc1c_order_statuses');

        // Debug
        register_setting('wc1c_settings', 'wc1c_debug_mode');
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(string $hook): void {
        if ('woocommerce_page_wc-1c-integration' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wc1c-admin',
            WC1C_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WC1C_VERSION
        );

        wp_enqueue_script(
            'wc1c-admin',
            WC1C_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WC1C_VERSION,
            true
        );

        wp_localize_script('wc1c-admin', 'wc1cAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc1c_admin_nonce'),
            'strings' => [
                'testing' => __('Testing...', 'wc-1c-integration'),
                'syncing' => __('Syncing...', 'wc-1c-integration'),
                'success' => __('Success!', 'wc-1c-integration'),
                'error' => __('Error', 'wc-1c-integration'),
                'confirm_clear' => __('Are you sure you want to clear the log?', 'wc-1c-integration'),
            ],
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        ?>
        <div class="wrap wc1c-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=wc-1c-integration&tab=general" 
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'wc-1c-integration'); ?>
                </a>
                <a href="?page=wc-1c-integration&tab=sync" 
                   class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Synchronization', 'wc-1c-integration'); ?>
                </a>
                <a href="?page=wc-1c-integration&tab=orders" 
                   class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Orders', 'wc-1c-integration'); ?>
                </a>
                <a href="?page=wc-1c-integration&tab=logs" 
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'wc-1c-integration'); ?>
                </a>
                <a href="?page=wc-1c-integration&tab=status" 
                   class="nav-tab <?php echo $active_tab === 'status' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Status', 'wc-1c-integration'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'sync':
                        $this->render_sync_tab();
                        break;
                    case 'orders':
                        $this->render_orders_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    case 'status':
                        $this->render_status_tab();
                        break;
                    default:
                        $this->render_general_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render general tab
     */
    private function render_general_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wc1c_settings'); ?>

            <h2><?php _e('Connection Settings', 'wc-1c-integration'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Exchange', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_enabled" value="yes" 
                                   <?php checked(get_option('wc1c_enabled', 'yes'), 'yes'); ?>>
                            <?php _e('Enable data exchange with 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Exchange URL', 'wc-1c-integration'); ?></th>
                    <td>
                        <code><?php echo esc_html(WC1C_Exchange_Endpoint::get_exchange_url()); ?></code>
                        <p class="description">
                            <?php _e('Use this URL in 1C exchange settings', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Username', 'wc-1c-integration'); ?></th>
                    <td>
                        <input type="text" name="wc1c_username" 
                               value="<?php echo esc_attr(get_option('wc1c_username', '')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Username for 1C authentication', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Password', 'wc-1c-integration'); ?></th>
                    <td>
                        <input type="password" name="wc1c_password" 
                               value="<?php echo esc_attr(get_option('wc1c_password', '')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Password for 1C authentication', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Debug Mode', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_debug_mode" value="yes" 
                                   <?php checked(get_option('wc1c_debug_mode', 'no'), 'yes'); ?>>
                            <?php _e('Enable detailed logging', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2><?php _e('Test Connection', 'wc-1c-integration'); ?></h2>
            <p>
                <button type="button" class="button" id="wc1c-test-connection">
                    <?php _e('Test Connection', 'wc-1c-integration'); ?>
                </button>
                <span id="wc1c-test-result"></span>
            </p>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render sync tab
     */
    private function render_sync_tab(): void {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wc1c_settings'); ?>

            <h2><?php _e('Catalog Synchronization', 'wc-1c-integration'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Sync Categories', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_sync_categories" value="yes" 
                                   <?php checked(get_option('wc1c_sync_categories', 'yes'), 'yes'); ?>>
                            <?php _e('Import categories from 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Sync Attributes', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_sync_attributes" value="yes" 
                                   <?php checked(get_option('wc1c_sync_attributes', 'yes'), 'yes'); ?>>
                            <?php _e('Import product attributes from 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Sync Images', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_sync_images" value="yes" 
                                   <?php checked(get_option('wc1c_sync_images', 'yes'), 'yes'); ?>>
                            <?php _e('Import product images from 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Sync Prices', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_sync_prices" value="yes" 
                                   <?php checked(get_option('wc1c_sync_prices', 'yes'), 'yes'); ?>>
                            <?php _e('Update prices from 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Sync Stock', 'wc-1c-integration'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wc1c_sync_stock" value="yes" 
                                   <?php checked(get_option('wc1c_sync_stock', 'yes'), 'yes'); ?>>
                            <?php _e('Update stock quantities from 1C', 'wc-1c-integration'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2><?php _e('Price Settings', 'wc-1c-integration'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Price Type', 'wc-1c-integration'); ?></th>
                    <td>
                        <input type="text" name="wc1c_price_type" 
                               value="<?php echo esc_attr(get_option('wc1c_price_type', 'Розничная')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Name of price type from 1C to use (e.g., "Розничная", "Оптовая")', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Warehouse', 'wc-1c-integration'); ?></th>
                    <td>
                        <input type="text" name="wc1c_warehouse" 
                               value="<?php echo esc_attr(get_option('wc1c_warehouse', '')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Warehouse name to use for stock (leave empty for all warehouses)', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render orders tab
     */
    private function render_orders_tab(): void {
        $order_statuses = wc_get_order_statuses();
        $selected = get_option('wc1c_order_statuses', ['wc-processing', 'wc-completed']);
        
        if (!is_array($selected)) {
            $selected = [$selected];
        }
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wc1c_settings'); ?>

            <h2><?php _e('Order Export Settings', 'wc-1c-integration'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Export Order Statuses', 'wc-1c-integration'); ?></th>
                    <td>
                        <?php foreach ($order_statuses as $status => $label): ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" name="wc1c_order_statuses[]" 
                                       value="<?php echo esc_attr($status); ?>"
                                       <?php checked(in_array($status, $selected)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php _e('Orders with these statuses will be exported to 1C', 'wc-1c-integration'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php _e('Export Statistics', 'wc-1c-integration'); ?></h2>
            <?php
            $stats = wc1c()->order_sync->get_export_stats();
            ?>
            <table class="widefat" style="max-width: 400px;">
                <tr>
                    <td><?php _e('Total Orders', 'wc-1c-integration'); ?></td>
                    <td><strong><?php echo esc_html($stats['total_orders']); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Exported', 'wc-1c-integration'); ?></td>
                    <td><strong><?php echo esc_html($stats['exported']); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Pending Export', 'wc-1c-integration'); ?></td>
                    <td><strong><?php echo esc_html($stats['pending_export']); ?></strong></td>
                </tr>
                <tr>
                    <td><?php _e('Needs Update', 'wc-1c-integration'); ?></td>
                    <td><strong><?php echo esc_html($stats['needs_update']); ?></strong></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab(): void {
        $log = WC1C_Logger::get_log(200);
        ?>
        <h2><?php _e('Exchange Log', 'wc-1c-integration'); ?></h2>
        
        <p>
            <button type="button" class="button" id="wc1c-refresh-log">
                <?php _e('Refresh', 'wc-1c-integration'); ?>
            </button>
            <button type="button" class="button" id="wc1c-clear-log">
                <?php _e('Clear Log', 'wc-1c-integration'); ?>
            </button>
        </p>

        <div id="wc1c-log-container">
            <textarea id="wc1c-log" readonly style="width: 100%; height: 500px; font-family: monospace; font-size: 12px;"><?php echo esc_textarea($log); ?></textarea>
        </div>
        <?php
    }

    /**
     * Render status tab
     */
    private function render_status_tab(): void {
        global $wpdb;
        
        $mapping_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc1c_id_mapping");
        $sync_log = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wc1c_sync_log ORDER BY id DESC LIMIT 20"
        );
        ?>
        <h2><?php _e('System Status', 'wc-1c-integration'); ?></h2>
        
        <table class="widefat" style="max-width: 600px;">
            <tr>
                <td><?php _e('Plugin Version', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(WC1C_VERSION); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('WooCommerce Version', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(WC()->version); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('PHP Version', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(PHP_VERSION); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('Exchange URL', 'wc-1c-integration'); ?></td>
                <td><code><?php echo esc_html(WC1C_Exchange_Endpoint::get_exchange_url()); ?></code></td>
            </tr>
            <tr>
                <td><?php _e('ID Mappings', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html($mapping_count); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('Max Upload Size', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(size_format(wp_max_upload_size())); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('Memory Limit', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(ini_get('memory_limit')); ?></strong></td>
            </tr>
            <tr>
                <td><?php _e('Max Execution Time', 'wc-1c-integration'); ?></td>
                <td><strong><?php echo esc_html(ini_get('max_execution_time')); ?>s</strong></td>
            </tr>
        </table>

        <h2><?php _e('Recent Sync Operations', 'wc-1c-integration'); ?></h2>
        
        <?php if (empty($sync_log)): ?>
            <p><?php _e('No sync operations recorded yet.', 'wc-1c-integration'); ?></p>
        <?php else: ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php _e('Type', 'wc-1c-integration'); ?></th>
                        <th><?php _e('Direction', 'wc-1c-integration'); ?></th>
                        <th><?php _e('Status', 'wc-1c-integration'); ?></th>
                        <th><?php _e('Message', 'wc-1c-integration'); ?></th>
                        <th><?php _e('Date', 'wc-1c-integration'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sync_log as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->sync_type); ?></td>
                            <td><?php echo esc_html($log->direction); ?></td>
                            <td>
                                <span class="wc1c-status wc1c-status-<?php echo esc_attr($log->status); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td><?php echo esc_html($log->completed_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('wc1c_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-1c-integration'));
        }

        // Check exchange URL accessibility
        $url = WC1C_Exchange_Endpoint::get_exchange_url() . '?type=catalog&mode=checkauth';
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);

        if ($code === 200 && strpos($body, 'success') !== false) {
            wp_send_json_success(__('Connection successful!', 'wc-1c-integration'));
        } else {
            wp_send_json_error(sprintf(
                __('Connection failed. Response code: %d', 'wc-1c-integration'),
                $code
            ));
        }
    }

    /**
     * AJAX: Manual sync
     */
    public function ajax_manual_sync(): void {
        check_ajax_referer('wc1c_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-1c-integration'));
        }

        $type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : '';

        try {
            switch ($type) {
                case 'export_orders':
                    $xml = wc1c()->order_sync->export_orders_xml();
                    wp_send_json_success([
                        'message' => __('Orders exported successfully', 'wc-1c-integration'),
                        'count' => substr_count($xml, '<Документ>'),
                    ]);
                    break;
                default:
                    wp_send_json_error(__('Unknown sync type', 'wc-1c-integration'));
            }
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX: Clear log
     */
    public function ajax_clear_log(): void {
        check_ajax_referer('wc1c_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission denied', 'wc-1c-integration'));
        }

        WC1C_Logger::clear_log();
        wp_send_json_success(__('Log cleared', 'wc-1c-integration'));
    }
}

// Initialize
new WC1C_Admin_Settings();
