<?php
/**
 * Plugin Name: WooCommerce Интеграция с 1С
 * Plugin URI: https://github.com/by-sonic/wc-1c-integration
 * Description: Полная двусторонняя интеграция WooCommerce с 1С через протокол CommerceML. Синхронизация товаров, цен, остатков и заказов.
 * Version: 1.0.0
 * Author: by-sonic
 * Author URI: https://github.com/by-sonic
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wc-1c-integration
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4|8.4
 * WC requires at least: 5.0
 * WC tested up to: 10.6
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

// Константы плагина
define('WC1C_VERSION', '1.0.0');
define('WC1C_PLUGIN_FILE', __FILE__);
define('WC1C_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC1C_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC1C_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Главный класс плагина
 */
final class WC_1C_Integration {

    /** @var WC_1C_Integration|null Единственный экземпляр */
    private static ?WC_1C_Integration $instance = null;

    /** @var WC1C_CommerceML_Parser|null Парсер CommerceML */
    public ?WC1C_CommerceML_Parser $parser = null;

    /** @var WC1C_Product_Sync|null Синхронизация товаров */
    public ?WC1C_Product_Sync $product_sync = null;

    /** @var WC1C_Order_Sync|null Синхронизация заказов */
    public ?WC1C_Order_Sync $order_sync = null;

    /**
     * Получить единственный экземпляр
     */
    public static function instance(): WC_1C_Integration {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct() {
        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        register_activation_hook(WC1C_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WC1C_PLUGIN_FILE, [$this, 'deactivate']);
    }

    /**
     * Инициализация после загрузки всех плагинов — безопасно проверять WooCommerce
     */
    public function on_plugins_loaded(): void {
        load_plugin_textdomain(
            'wc-1c-integration',
            false,
            dirname(WC1C_PLUGIN_BASENAME) . '/languages/'
        );

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                echo 'Для работы плагина «Интеграция с 1С» необходим установленный и активированный WooCommerce.';
                echo '</p></div>';
            });
            return;
        }

        $this->includes();
        add_action('init', [$this, 'init'], 0);
    }

    /**
     * Подключение необходимых файлов
     */
    private function includes(): void {
        require_once WC1C_PLUGIN_DIR . 'includes/class-commerceml-parser.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-order-sync.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-exchange-endpoint.php';
        require_once WC1C_PLUGIN_DIR . 'includes/class-logger.php';

        if (is_admin()) {
            require_once WC1C_PLUGIN_DIR . 'includes/admin/class-admin-settings.php';
        }
    }

    /**
     * Инициализация компонентов плагина (вызывается на хуке init, после загрузки WooCommerce)
     */
    public function init(): void {
        $this->parser = new WC1C_CommerceML_Parser();
        $this->product_sync = new WC1C_Product_Sync();
        $this->order_sync = new WC1C_Order_Sync();

        new WC1C_Exchange_Endpoint();
    }

    /**
     * Активация плагина
     */
    public function activate(): void {
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        
        if (!file_exists($exchange_dir)) {
            wp_mkdir_p($exchange_dir);
        }

        $htaccess = $exchange_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, 'deny from all');
        }

        $this->create_tables();
        $this->set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Деактивация плагина
     */
    public function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Создание таблиц в базе данных
     */
    private function create_tables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = $wpdb->prefix . 'wc1c_id_mapping';
        dbDelta("CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            guid_1c varchar(36) NOT NULL,
            wc_id bigint(20) NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'product',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY guid_type (guid_1c, type),
            KEY wc_id (wc_id)
        ) {$charset_collate};");

        $log_table = $wpdb->prefix . 'wc1c_sync_log';
        dbDelta("CREATE TABLE {$log_table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            direction varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            items_processed int DEFAULT 0,
            items_failed int DEFAULT 0,
            started_at datetime,
            completed_at datetime,
            PRIMARY KEY  (id),
            KEY sync_type (sync_type),
            KEY status (status)
        ) {$charset_collate};");
    }

    /**
     * Установка настроек по умолчанию
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
 * Объявление совместимости с HPOS (WooCommerce 10.x+)
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Возвращает экземпляр плагина
 */
function wc1c(): WC_1C_Integration {
    return WC_1C_Integration::instance();
}

// Инициализация плагина
wc1c();
