<?php
/**
 * Order Sync
 *
 * Экспорт заказов из WooCommerce в 1С
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Order Sync class
 */
class WC1C_Order_Sync {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook for order status changes
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 10, 4);
    }

    /**
     * Get orders for export to 1C
     *
     * @param array $args Query arguments
     * @return array Orders data prepared for CommerceML export
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
     * Prepare single order for export
     *
     * @param WC_Order $order Order object
     * @return array Order data in CommerceML format
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
     * Generate GUID for order
     */
    private function generate_order_guid(WC_Order $order): string {
        $existing_guid = $order->get_meta('_wc1c_order_guid');
        
        if (!empty($existing_guid)) {
            return $existing_guid;
        }

        // Generate new GUID
        $guid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $order->update_meta_data('_wc1c_order_guid', $guid);
        $order->save();

        return $guid;
    }

    /**
     * Get customer GUID
     */
    private function get_customer_guid(WC_Order $order): string {
        $customer_id = $order->get_customer_id();
        
        if (!$customer_id) {
            // Guest customer - generate based on email
            $email = $order->get_billing_email();
            return md5('guest_' . $email);
        }

        $guid = get_user_meta($customer_id, '_wc1c_customer_guid', true);
        
        if (empty($guid)) {
            $guid = sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
            update_user_meta($customer_id, '_wc1c_customer_guid', $guid);
        }

        return $guid;
    }

    /**
     * Get billing data
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
     * Get shipping data
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
     * Get shipping method title
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
     * Get order items
     */
    private function get_order_items(WC_Order $order): array {
        $items = [];
        
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            // Get 1C GUID
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

            // Add variation attributes
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
     * Mark orders as exported
     *
     * @param array $order_ids Order IDs to mark
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
     * Handle order status change
     *
     * @param int $order_id Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     * @param WC_Order $order Order object
     */
    public function on_order_status_changed(int $order_id, string $old_status, string $new_status, WC_Order $order): void {
        // Mark for re-export if already exported
        if ($order->get_meta('_wc1c_exported') === '1') {
            $order->update_meta_data('_wc1c_needs_update', '1');
            $order->save();
        }
    }

    /**
     * Process order updates from 1C
     *
     * @param array $updates Array of order updates from 1C
     * @return array Results
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

                // Update order status
                if (!empty($update['status'])) {
                    $wc_status = $this->map_1c_status_to_wc($update['status']);
                    if ($wc_status && $order->get_status() !== $wc_status) {
                        $order->update_status($wc_status, __('Status updated from 1C', 'wc-1c-integration'));
                    }
                }

                // Update tracking number
                if (!empty($update['tracking_number'])) {
                    $order->update_meta_data('_tracking_number', $update['tracking_number']);
                }

                // Update 1C document number
                if (!empty($update['1c_document_number'])) {
                    $order->update_meta_data('_1c_document_number', $update['1c_document_number']);
                }

                $order->save();
                $results['updated']++;

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Order %s: %s', 'wc-1c-integration'),
                    $update['id'],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Find order by 1C GUID
     */
    private function find_order_by_guid(string $guid): ?WC_Order {
        $orders = wc_get_orders([
            'meta_key' => '_wc1c_order_guid',
            'meta_value' => $guid,
            'limit' => 1,
        ]);

        return !empty($orders) ? $orders[0] : null;
    }

    /**
     * Map 1C status to WooCommerce status
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
     * Export orders to XML
     *
     * @param array $args Query arguments
     * @return string XML content
     */
    public function export_orders_xml(array $args = []): string {
        $orders = $this->get_orders_for_export($args);
        
        $parser = new WC1C_CommerceML_Parser();
        return $parser->generate_orders_xml($orders);
    }

    /**
     * Get export statistics
     */
    public function get_export_stats(): array {
        global $wpdb;

        $stats = [
            'total_orders' => 0,
            'exported' => 0,
            'pending_export' => 0,
            'needs_update' => 0,
        ];

        // Get total orders
        $stats['total_orders'] = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'"
        );

        // Get exported count
        $stats['exported'] = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_wc1c_exported',
            '1'
        ));

        // Get needs update count
        $stats['needs_update'] = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
            '_wc1c_needs_update',
            '1'
        ));

        $stats['pending_export'] = $stats['total_orders'] - $stats['exported'];

        return $stats;
    }
}
