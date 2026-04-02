<?php
/**
 * Синхронизация заказов
 *
 * Экспорт заказов из WooCommerce в 1С
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Класс синхронизации заказов
 */
class WC1C_Order_Sync {

    /**
     * Конструктор
     */
    public function __construct() {
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    /**
     * Получение заказов для выгрузки в 1С
     *
     * @param array $args Параметры запроса
     * @return array Данные заказов, подготовленные для выгрузки в CommerceML
     */
    public function get_orders_for_export(array $args = []): array {
        $defaults = [
            'status' => get_option('wc1c_order_statuses', ['wc-processing', 'wc-completed']),
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_wc1c_exported',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_wc1c_exported',
                    'value' => '0',
                ],
                [
                    'key' => '_wc1c_needs_update',
                    'value' => '1',
                ],
            ],
        ];

        $args = wp_parse_args($args, $defaults);
        
        $orders = wc_get_orders($args);
        $export_data = [];

        foreach ($orders as $order) {
            $export_data[] = $this->prepare_order_for_export($order);
        }

        return $export_data;
    }

    /**
     * Подготовка одного заказа к выгрузке
     *
     * @param WC_Order $order Объект заказа
     * @return array Данные заказа в формате CommerceML
     */
    public function prepare_order_for_export(WC_Order $order): array {
        $order_data = [
            'id' => $this->generate_order_guid($order),
            'number' => $order->get_order_number(),
            'date' => $order->get_date_created()->format('Y-m-d'),
            'time' => $order->get_date_created()->format('H:i:s'),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'total' => $order->get_total(),
            'customer_id' => $this->get_customer_guid($order),
            'customer_note' => $order->get_customer_note(),
            'payment_method' => $order->get_payment_method_title(),
            'shipping_method' => $this->get_shipping_method_title($order),
            'shipping_total' => $order->get_shipping_total(),
            'paid_date' => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d') : '',
            'billing' => $this->get_billing_data($order),
            'shipping' => $this->get_shipping_data($order),
            'items' => $this->get_order_items($order),
        ];

        return $order_data;
    }

    /**
     * Генерация GUID для заказа
     */
    private function generate_order_guid(WC_Order $order): string {
        $existing_guid = $order->get_meta('_wc1c_order_guid');
        
        if (!empty($existing_guid)) {
            return $existing_guid;
        }

        $guid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );

        $order->update_meta_data('_wc1c_order_guid', $guid);
        $order->save();

        return $guid;
    }

    /**
     * Получение GUID покупателя
     */
    private function get_customer_guid(WC_Order $order): string {
        $customer_id = $order->get_customer_id();
        
        if (!$customer_id) {
            $email = $order->get_billing_email();
            return md5('guest_' . $email);
        }

        $guid = get_user_meta($customer_id, '_wc1c_customer_guid', true);
        
        if (empty($guid)) {
            $guid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff), random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000,
                random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
            );
            update_user_meta($customer_id, '_wc1c_customer_guid', $guid);
        }

        return $guid;
    }

    /**
     * Получение данных плательщика
     */
    private function get_billing_data(WC_Order $order): array {
        return [
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'company' => $order->get_billing_company(),
            'address_1' => $order->get_billing_address_1(),
            'address_2' => $order->get_billing_address_2(),
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'postcode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
        ];
    }

    /**
     * Получение данных доставки
     */
    private function get_shipping_data(WC_Order $order): array {
        return [
            'first_name' => $order->get_shipping_first_name(),
            'last_name' => $order->get_shipping_last_name(),
            'company' => $order->get_shipping_company(),
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city' => $order->get_shipping_city(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'country' => $order->get_shipping_country(),
        ];
    }

    /**
     * Получение названия способа доставки
     */
    private function get_shipping_method_title(WC_Order $order): string {
        $shipping_methods = $order->get_shipping_methods();
        
        if (empty($shipping_methods)) {
            return '';
        }

        $method = reset($shipping_methods);
        return $method->get_method_title();
    }

    /**
     * Получение позиций заказа
     */
    private function get_order_items(WC_Order $order): array {
        $items = [];
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            $product_1c_id = '';
            if ($product) {
                $product_1c_id = $product->get_meta('_1c_guid');
            }

            $item_data = [
                'id' => $item_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'product_1c_id' => $product_1c_id,
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'quantity' => $item->get_quantity(),
                'price' => $order->get_item_subtotal($item, false, false),
                'total' => $item->get_total(),
                'tax' => $item->get_total_tax(),
                'discount' => $item->get_subtotal() - $item->get_total(),
            ];

            if ($variation_id && $product) {
                $item_data['attributes'] = [];
                foreach ($product->get_attributes() as $attr_name => $attr_value) {
                    $item_data['attributes'][] = [
                        'name' => wc_attribute_label($attr_name),
                        'value' => $attr_value,
                    ];
                }
            }

            $items[] = $item_data;
        }

        return $items;
    }

    /**
     * Пометка заказов как выгруженных
     *
     * @param array $order_ids ID заказов для пометки
     */
    public function mark_orders_exported(array $order_ids): void {
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_wc1c_exported', '1');
                $order->update_meta_data('_wc1c_exported_at', current_time('mysql'));
                $order->delete_meta_data('_wc1c_needs_update');
                $order->save();
            }
        }
    }

    /**
     * Обработка изменения статуса заказа
     *
     * @param int $order_id ID заказа
     * @param string $old_status Старый статус
     * @param string $new_status Новый статус
     * @param WC_Order $order Объект заказа
     */
    public function on_order_status_changed(int $order_id, string $old_status, string $new_status, WC_Order $order): void {
        if ($order->get_meta('_wc1c_exported') === '1') {
            $order->update_meta_data('_wc1c_needs_update', '1');
            $order->save();
        }
    }

    /**
     * Обработка обновлений заказов из 1С
     *
     * @param array $updates Массив обновлений заказов из 1С
     * @return array Результаты
     */
    public function process_order_updates(array $updates): array {
        $results = [
            'updated' => 0,
            'failed' => 0,
            'not_found' => 0,
            'errors' => [],
        ];

        foreach ($updates as $update) {
            try {
                $order = $this->find_order_by_guid($update['id']);
                
                if (!$order) {
                    $results['not_found']++;
                    continue;
                }

                if (!empty($update['status'])) {
                    $wc_status = $this->map_1c_status_to_wc($update['status']);
                    if ($wc_status && $order->get_status() !== $wc_status) {
                        $order->update_status($wc_status, 'Статус обновлён из 1С');
                    }
                }

                if (!empty($update['tracking_number'])) {
                    $order->update_meta_data('_tracking_number', $update['tracking_number']);
                }

                if (!empty($update['1c_document_number'])) {
                    $order->update_meta_data('_1c_document_number', $update['1c_document_number']);
                }

                $order->save();
                $results['updated']++;

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Заказ %s: %s',
                    $update['id'],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Поиск заказа по GUID из 1С (совместим с HPOS)
     */
    private function find_order_by_guid(string $guid): ?WC_Order {
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_query' => [
                [
                    'key'   => '_wc1c_order_guid',
                    'value' => $guid,
                ],
            ],
        ]);

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Маппинг статусов 1С в статусы WooCommerce
     */
    private function map_1c_status_to_wc(string $status_1c): string {
        $status_map = [
            'Новый' => 'pending',
            'В обработке' => 'processing',
            'На удержании' => 'on-hold',
            'Выполнен' => 'completed',
            'Отменен' => 'cancelled',
            'Возврат' => 'refunded',
            'Ошибка' => 'failed',
            'Отгружен' => 'completed',
            'Оплачен' => 'processing',
        ];

        return $status_map[$status_1c] ?? '';
    }

    /**
     * Выгрузка заказов в XML
     *
     * @param array $args Параметры запроса
     * @return string XML-содержимое
     */
    public function export_orders_xml(array $args = []): string {
        $orders = $this->get_orders_for_export($args);
        
        $parser = new WC1C_CommerceML_Parser();
        return $parser->generate_orders_xml($orders);
    }

    /**
     * Получение статистики выгрузки (совместимо с HPOS)
     */
    public function get_export_stats(): array {
        global $wpdb;

        $stats = [
            'total_orders' => 0,
            'exported' => 0,
            'pending_export' => 0,
            'needs_update' => 0,
        ];

        $hpos_enabled = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos_enabled) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table   = $wpdb->prefix . 'wc_orders_meta';

            $stats['total_orders'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_order'"
            );

            $stats['exported'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = %s AND meta_value = %s",
                '_wc1c_exported',
                '1'
            ) );

            $stats['needs_update'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = %s AND meta_value = %s",
                '_wc1c_needs_update',
                '1'
            ) );
        } else {
            $stats['total_orders'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'"
            );

            $stats['exported'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_wc1c_exported',
                '1'
            ) );

            $stats['needs_update'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                '_wc1c_needs_update',
                '1'
            ) );
        }

        $stats['pending_export'] = $stats['total_orders'] - $stats['exported'];

        return $stats;
    }
}
