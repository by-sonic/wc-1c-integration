<?php
/**
 * Plugin Name: WooCommerce 1C Integration
 * Plugin URI: https://github.com/your-username/wc-1c-integration
 * Description: Полная двусторонняя интеграция WooCommerce с 1С через протокол CommerceML. Синхронизация товаров, цен, остатков и заказов.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://github.com/your-username
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wc-1c-integration
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

// Plugin constants
define('WC1C_VERSION', '1.0.0');
define('WC1C_PLUGIN_FILE', __FILE__);
define('WC1C_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC1C_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC1C_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class WC_1C_Integration {

    /**
     * Single instance
     */
    private static ?WC_1C_Integration $instance = null;

    /**
     * CommerceML parser instance
     */
    public ?WC1C_CommerceML_Parser $parser = null;

    /**
     * Product sync instance
     */
    public ?WC1C_Product_Sync $product_sync = null;

    /**
     * Order sync instance
     */
    public ?WC1C_Order_Sync $order_sync = null;

    /**
     * Get single instance
     */
    public static function instance(): WC_1C_Integration {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo esc_html__('WooCommerce 1C Integration requires WooCommerce to be installed and active.', 'wc-1c-integration');
                echo '</p></div>';
            });
            return;
        }
    }

    /**
     * Include required files
     */
    private function includes(): void {
        // Core classes
        require_once WC1C_PLUGIN_DIR . 'includes/class-commerceml-parser.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-order-sync.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-exchange-endpoint.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-logger.php';

        // Admin classes
        if (is_admin()) {
            require_once WC1C_PLUGIN_DIR . 'includes/admin/class-admin-settings.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('init', [$this, 'init'], 0);
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        
        register_activation_hook(WC1C_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WC1C_PLUGIN_FILE, [$this, 'deactivate']);
    }

    /**
     * Initialize plugin
     */
    public function init(): void {
        $this->parser = new WC1C_CommerceML_Parser();
        $this->product_sync = new WC1C_Product_Sync();
        $this->order_sync = new WC1C_Order_Sync();

        // Register exchange endpoint
        new WC1C_Exchange_Endpoint();
    }

    /**
     * Load translations
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'wc-1c-integration',
            false,
            dirname(WC1C_PLUGIN_BASENAME) . '/languages/'
        );
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        // Create exchange directory
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        
        if (!file_exists($exchange_dir)) {
            wp_mkdir_p($exchange_dir);
        }

        // Add .htaccess to protect uploads
        $htaccess = $exchange_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, 'deny from all');
        }

        // Create database tables
        $this->create_tables();

        // Set default options
        $this->set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables
     */
    private function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Table for mapping 1C IDs to WooCommerce IDs
        $table_name = $wpdb->prefix . 'wc1c_id_mapping';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            guid_1c varchar(36) NOT NULL,
            wc_id bigint(20) NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'product',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY guid_type (guid_1c, type),
            KEY wc_id (wc_id)
        ) {$charset_collate};";

        // Table for sync log
        $log_table = $wpdb->prefix . 'wc1c_sync_log';
        
        $sql .= "CREATE TABLE IF NOT EXISTS {$log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            direction varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            items_processed int DEFAULT 0,
            items_failed int DEFAULT 0,
            started_at datetime,
            completed_at datetime,
            PRIMARY KEY (id),
            KEY sync_type (sync_type),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Set default plugin options
     */
    private function set_default_options(): void {
        $defaults = [
            'wc1c_enabled' => 'yes',
            'wc1c_username' => '',
            'wc1c_password' => '',
            'wc1c_sync_images' => 'yes',
            'wc1c_sync_categories' => 'yes',
            'wc1c_sync_attributes' => 'yes',
            'wc1c_sync_stock' => 'yes',
            'wc1c_sync_prices' => 'yes',
            'wc1c_order_statuses' => ['wc-processing', 'wc-completed'],
            'wc1c_price_type' => 'Розничная',
            'wc1c_warehouse' => '',
            'wc1c_debug_mode' => 'no',
        ];

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }
    }
}

/**
 * Returns main plugin instance
 */
function wc1c(): WC_1C_Integration {
    return WC_1C_Integration::instance();
}

// Initialize plugin
wc1c();
