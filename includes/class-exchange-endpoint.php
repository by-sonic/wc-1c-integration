<?php
/**
 * Эндпоинт обмена
 *
 * HTTP-эндпоинт для обмена данными с 1С по протоколу CommerceML
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Класс эндпоинта обмена
 */
class WC1C_Exchange_Endpoint {

    /** @var string URL-слаг обмена */
    const ENDPOINT_SLUG = '1c-exchange';

    /** @var string Путь к файлу сессии */
    private string $session_file = '';

    /**
     * Конструктор
     */
    public function __construct() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_request']);
        add_action('parse_request', [$this, 'parse_request_fallback']);
    }

    /**
     * Добавление правил перезаписи URL
     */
    public function add_rewrite_rules(): void {
        add_rewrite_rule(
            '^' . self::ENDPOINT_SLUG . '/?$',
            'index.php?wc1c_exchange=1',
            'top'
        );
    }

    /**
     * Добавление переменных запроса
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'wc1c_exchange';
        return $vars;
    }

    /**
     * Запасной вариант для окружений без mod_rewrite (Docker, nginx)
     */
    public function parse_request_fallback(\WP $wp): void {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');

        if ($path === self::ENDPOINT_SLUG && empty($wp->query_vars['wc1c_exchange'])) {
            $wp->query_vars['wc1c_exchange'] = '1';
        }
    }

    /**
     * Обработка запроса обмена
     */
    public function handle_request(): void {
        if (!get_query_var('wc1c_exchange')) {
            return;
        }

        nocache_headers();

        if ('yes' !== get_option('wc1c_enabled', 'yes')) {
            $this->send_error('Обмен данными отключён');
        }

        if (!$this->authenticate()) {
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="1C Exchange"');
            $this->send_error('Требуется авторизация');
        }

        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
        $mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : '';

        WC1C_Logger::log("Запрос обмена: тип={$type}, режим={$mode}", 'info');

        try {
            switch ($type) {
                case 'catalog':
                    $this->handle_catalog($mode);
                    break;
                case 'sale':
                    $this->handle_sale($mode);
                    break;
                default:
                    $this->send_error('Неизвестный тип обмена');
            }
        } catch (Exception $e) {
            WC1C_Logger::log('Ошибка обмена: ' . $e->getMessage(), 'error');
            $this->send_error($e->getMessage());
        }

        exit;
    }

    /**
     * Авторизация запроса
     */
    private function authenticate(): bool {
        $username = get_option('wc1c_username', '');
        $password = get_option('wc1c_password', '');

        if (empty($username) && empty($password)) {
            return true;
        }

        $auth_user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
        $auth_pass = isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';

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
     * Обработка обмена каталогом
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
                $this->send_error('Неизвестный режим каталога');
        }
    }

    /**
     * Обработка обмена продажами (заказами)
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
                $this->send_error('Неизвестный режим продаж');
        }
    }

    /**
     * Проверка авторизации
     */
    private function check_auth(): void {
        $session_id = bin2hex(random_bytes(16));
        $upload_dir = wp_upload_dir();
        $this->session_file = $upload_dir['basedir'] . '/wc-1c-exchange/session_' . $session_id;

        file_put_contents($this->session_file, time());

        echo "success\n";
        echo "PHPSESSID\n";
        echo $session_id;
    }

    /**
     * Инициализация обмена каталогом
     */
    private function init_catalog(): void {
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';

        $this->clean_exchange_dir($exchange_dir);

        echo "zip=no\n";
        echo "file_limit=" . $this->get_file_limit() . "\n";
    }

    /**
     * Инициализация обмена продажами
     */
    private function init_sale(): void {
        echo "zip=no\n";
        echo "file_limit=" . $this->get_file_limit() . "\n";
    }

    /**
     * Приём файла от 1С
     */
    private function receive_file(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        if (empty($filename)) {
            $this->send_error('Имя файла обязательно');
        }

        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        
        if (!file_exists($exchange_dir)) {
            wp_mkdir_p($exchange_dir);
        }

        $file_path = $exchange_dir . '/' . $filename;
        $file_dir = dirname($file_path);
        
        if (!file_exists($file_dir)) {
            wp_mkdir_p($file_dir);
        }

        $data = file_get_contents('php://input');
        
        if (empty($data)) {
            $this->send_error('Данные не получены');
        }

        $result = file_put_contents($file_path, $data, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            $this->send_error('Ошибка записи файла');
        }

        WC1C_Logger::log("Получен файл: {$filename}, размер: " . strlen($data), 'info');

        echo "success\n";
    }

    /**
     * Импорт каталога из полученных файлов
     */
    private function import_catalog(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';

        if (empty($filename)) {
            if (file_exists($exchange_dir . '/import.xml')) {
                $filename = 'import.xml';
            } elseif (file_exists($exchange_dir . '/offers.xml')) {
                $filename = 'offers.xml';
            } else {
                $this->send_error('Нет файлов для импорта');
            }
        }

        $file_path = $exchange_dir . '/' . $filename;
        
        if (!file_exists($file_path)) {
            $this->send_error(sprintf('Файл не найден: %s', $filename));
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $parser = wc1c()->parser;
        $product_sync = wc1c()->product_sync;

        try {
            if (strpos($filename, 'import') !== false) {
                $data = $parser->parse_import($file_path);
                
                if (!empty($data['categories'])) {
                    $cat_results = $product_sync->sync_categories($data['categories']);
                    WC1C_Logger::log("Категории синхронизированы: " . json_encode($cat_results), 'info');
                }

                if (!empty($data['products'])) {
                    $prod_results = $product_sync->sync_products($data['products']);
                    WC1C_Logger::log("Товары синхронизированы: " . json_encode($prod_results), 'info');
                }

                echo "success\n";
                
            } elseif (strpos($filename, 'offers') !== false) {
                $data = $parser->parse_offers($file_path);
                
                if (!empty($data['offers'])) {
                    $results = $product_sync->update_offers($data['offers']);
                    WC1C_Logger::log("Предложения обновлены: " . json_encode($results), 'info');
                }

                echo "success\n";
                
            } else {
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

            $this->log_sync('catalog_import', 'incoming', 'success');

        } catch (Exception $e) {
            $this->log_sync('catalog_import', 'incoming', 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Выгрузка заказов в 1С
     */
    private function export_orders(): void {
        $order_sync = wc1c()->order_sync;

        $xml = $order_sync->export_orders_xml();

        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Length: ' . strlen($xml));

        echo $xml;

        $this->log_sync('orders_export', 'outgoing', 'success');
    }

    /**
     * Подтверждение успешной выгрузки заказов
     */
    private function mark_orders_success(): void {
        $data = file_get_contents('php://input');
        
        $order_sync = wc1c()->order_sync;
        $orders = $order_sync->get_orders_for_export();
        
        $order_ids = array_map(function($order) {
            return $order['number'];
        }, $orders);

        if (!empty($order_ids)) {
            $order_sync->mark_orders_exported($order_ids);
        }

        echo "success\n";
    }

    /**
     * Приём файла обновлений заказов из 1С
     */
    private function receive_order_file(): void {
        $filename = isset($_GET['filename']) ? sanitize_file_name($_GET['filename']) : '';
        
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange';
        $file_path = $exchange_dir . '/' . $filename;

        $data = file_get_contents('php://input');
        
        if (empty($data)) {
            $this->send_error('Данные не получены');
        }

        file_put_contents($file_path, $data, FILE_APPEND | LOCK_EX);

        if (strpos($filename, 'orders') !== false && file_exists($file_path)) {
            $this->process_order_updates($file_path);
        }

        echo "success\n";
    }

    /**
     * Обработка обновлений заказов из 1С
     */
    private function process_order_updates(string $file_path): void {
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
            WC1C_Logger::log("Обновления заказов обработаны: " . json_encode($results), 'info');
        }
    }

    /**
     * Очистка директории обмена
     */
    private function clean_exchange_dir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');
        $now = time();
        $max_age = 3600; // 1 час

        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) > $max_age) {
                    @unlink($file);
                }
            } elseif (is_dir($file)) {
                $this->clean_exchange_dir($file);
            }
        }
    }

    /**
     * Получение лимита размера файла для загрузки
     */
    private function get_file_limit(): int {
        $upload_max = $this->parse_size(ini_get('upload_max_filesize'));
        $post_max = $this->parse_size(ini_get('post_max_size'));
        $memory = $this->parse_size(ini_get('memory_limit'));

        $limit = min($upload_max, $post_max, $memory / 4);
        
        return max(1024 * 1024, min($limit, 100 * 1024 * 1024));
    }

    /**
     * Преобразование строки размера в байты
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
     * Запись в журнал синхронизации
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
     * Отправка ошибки
     */
    private function send_error(string $message): void {
        echo "failure\n";
        echo $message;
        exit;
    }

    /**
     * Получить URL обмена
     */
    public static function get_exchange_url(): string {
        return home_url('/' . self::ENDPOINT_SLUG . '/');
    }
}
