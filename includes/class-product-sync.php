<?php
/**
 * Синхронизация товаров
 *
 * Синхронизация товаров из 1С в WooCommerce
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Класс синхронизации товаров
 */
class WC1C_Product_Sync {

    /** @var string Имя таблицы связей ID */
    private string $mapping_table;

    /**
     * Конструктор
     */
    public function __construct() {
        global $wpdb;
        $this->mapping_table = $wpdb->prefix . 'wc1c_id_mapping';
    }

    /**
     * Синхронизация категорий из 1С
     *
     * @param array $categories Разобранные категории из CommerceML
     * @return array Результаты: кол-во созданных/обновлённых
     */
    public function sync_categories(array $categories): array {
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if ('yes' !== get_option('wc1c_sync_categories', 'yes')) {
            return $results;
        }

        $sorted = $this->sort_categories_by_hierarchy($categories);

        foreach ($sorted as $category) {
            try {
                $result = $this->sync_single_category($category);
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Категория «%s»: %s',
                    $category['name'],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Сортировка категорий по иерархии (родительские — первыми)
     */
    private function sort_categories_by_hierarchy(array $categories): array {
        $sorted = [];
        $remaining = $categories;
        $max_iterations = count($categories) * 2;
        $iteration = 0;

        while (!empty($remaining) && $iteration < $max_iterations) {
            foreach ($remaining as $id => $category) {
                if (empty($category['parent_id']) || isset($sorted[$category['parent_id']])) {
                    $sorted[$id] = $category;
                    unset($remaining[$id]);
                }
            }
            $iteration++;
        }

        foreach ($remaining as $id => $category) {
            $sorted[$id] = $category;
        }

        return $sorted;
    }

    /**
     * Синхронизация одной категории
     */
    private function sync_single_category(array $category): array {
        $wc_term_id = $this->get_wc_id($category['id'], 'category');
        $parent_id = 0;

        if (!empty($category['parent_id'])) {
            $parent_id = $this->get_wc_id($category['parent_id'], 'category');
        }

        $term_data = [
            'description' => $category['description'] ?? '',
            'parent' => $parent_id,
        ];

        if ($wc_term_id) {
            $result = wp_update_term($wc_term_id, 'product_cat', array_merge(
                ['name' => $category['name']],
                $term_data
            ));
            $action = 'updated';
        } else {
            $result = wp_insert_term($category['name'], 'product_cat', $term_data);
            $action = 'created';
        }

        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }

        $term_id = is_array($result) ? $result['term_id'] : $result;
        
        $this->save_mapping($category['id'], $term_id, 'category');

        return ['action' => $action, 'term_id' => $term_id];
    }

    /**
     * Синхронизация товаров из 1С
     *
     * @param array $products Разобранные товары из CommerceML
     * @param array $offers Разобранные предложения (цены/остатки)
     * @return array Результаты
     */
    public function sync_products(array $products, array $offers = []): array {
        $results = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        // Первый проход: простые товары и родители вариативных
        foreach ($products as $product) {
            if ($product['is_variation']) {
                continue;
            }

            try {
                $offer = $offers[$product['id']] ?? [];
                $result = $this->sync_single_product($product, $offer);
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } elseif ($result['action'] === 'updated') {
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Товар «%s» (Артикул: %s): %s',
                    $product['name'],
                    $product['sku'],
                    $e->getMessage()
                );
                WC1C_Logger::log("Ошибка синхронизации товара: {$product['id']} - " . $e->getMessage(), 'error');
            }
        }

        // Второй проход: вариации
        foreach ($products as $product) {
            if (!$product['is_variation']) {
                continue;
            }

            try {
                $offer = $offers[$product['id']] ?? [];
                $result = $this->sync_variation($product, $offer);
                
                if ($result['action'] === 'created') {
                    $results['created']++;
                } elseif ($result['action'] === 'updated') {
                    $results['updated']++;
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Вариация «%s»: %s',
                    $product['name'],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Синхронизация одного товара
     */
    private function sync_single_product(array $product_data, array $offer = []): array {
        if ($product_data['status'] === 'deleted') {
            return $this->handle_deleted_product($product_data);
        }

        $wc_product_id = $this->get_wc_id($product_data['id'], 'product');
        
        $has_variations = $this->product_has_variations($product_data['id']);

        if ($wc_product_id) {
            $wc_product = wc_get_product($wc_product_id);
            if (!$wc_product) {
                $wc_product_id = null;
            }
        }

        if ($wc_product_id) {
            $wc_product = wc_get_product($wc_product_id);
            $action = 'updated';
        } else {
            if ($has_variations) {
                $wc_product = new WC_Product_Variable();
            } else {
                $wc_product = new WC_Product_Simple();
            }
            $action = 'created';
        }

        $this->set_product_data($wc_product, $product_data, $offer);

        $product_id = $wc_product->save();

        if (!$product_id) {
            throw new Exception('Не удалось сохранить товар');
        }

        $this->save_mapping($product_data['id'], $product_id, 'product');

        if (!empty($product_data['images']) && 'yes' === get_option('wc1c_sync_images', 'yes')) {
            $this->sync_product_images($product_id, $product_data['images']);
        }

        return ['action' => $action, 'product_id' => $product_id];
    }

    /**
     * Установка данных товара
     */
    private function set_product_data(WC_Product $product, array $data, array $offer = []): void {
        $product->set_name($data['name']);
        
        if (!empty($data['sku'])) {
            $existing_id = wc_get_product_id_by_sku($data['sku']);
            if (!$existing_id || $existing_id === $product->get_id()) {
                $product->set_sku($data['sku']);
            }
        }

        if (!empty($data['description'])) {
            $product->set_description($data['description']);
        }

        if (!empty($data['short_description'])) {
            $product->set_short_description(wp_trim_words($data['short_description'], 30));
        }

        if (!empty($data['categories'])) {
            $cat_ids = [];
            foreach ($data['categories'] as $cat_1c_id) {
                $wc_cat_id = $this->get_wc_id($cat_1c_id, 'category');
                if ($wc_cat_id) {
                    $cat_ids[] = $wc_cat_id;
                }
            }
            if (!empty($cat_ids)) {
                $product->set_category_ids($cat_ids);
            }
        }

        if (!empty($data['weight'])) {
            $product->set_weight($data['weight']);
        }

        if (!empty($data['attributes']) && 'yes' === get_option('wc1c_sync_attributes', 'yes')) {
            $this->set_product_attributes($product, $data['attributes']);
        }

        if (!empty($offer['prices']) && 'yes' === get_option('wc1c_sync_prices', 'yes')) {
            $this->set_product_prices($product, $offer['prices']);
        }

        if ('yes' === get_option('wc1c_sync_stock', 'yes')) {
            $product->set_manage_stock(true);
            $stock = $offer['total_stock'] ?? 0;
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }

        $product->set_status('publish');

        $product->update_meta_data('_1c_guid', $data['id']);
        
        if (!empty($data['barcode'])) {
            $product->update_meta_data('_1c_barcode', $data['barcode']);
        }

        if (!empty($data['manufacturer'])) {
            $product->update_meta_data('_1c_manufacturer', $data['manufacturer']);
        }
    }

    /**
     * Установка свойств товара
     */
    private function set_product_attributes(WC_Product $product, array $attributes): void {
        $product_attributes = [];

        foreach ($attributes as $attr) {
            $attr_name = wc_sanitize_taxonomy_name($attr['name']);
            $attr_slug = 'pa_' . $attr_name;

            $this->maybe_create_attribute_taxonomy($attr_name, $attr['name']);

            $term = get_term_by('name', $attr['value'], $attr_slug);
            if (!$term) {
                $result = wp_insert_term($attr['value'], $attr_slug);
                if (!is_wp_error($result)) {
                    $term = get_term($result['term_id'], $attr_slug);
                }
            }

            if ($term) {
                $product_attributes[$attr_slug] = new WC_Product_Attribute();
                $product_attributes[$attr_slug]->set_id(wc_attribute_taxonomy_id_by_name($attr_slug));
                $product_attributes[$attr_slug]->set_name($attr_slug);
                $product_attributes[$attr_slug]->set_options([$term->term_id]);
                $product_attributes[$attr_slug]->set_visible(true);
                $product_attributes[$attr_slug]->set_variation(false);
            }
        }

        if (!empty($product_attributes)) {
            $product->set_attributes($product_attributes);
        }
    }

    /**
     * Создание таксономии свойств при необходимости
     */
    private function maybe_create_attribute_taxonomy(string $slug, string $name): void {
        if (taxonomy_exists('pa_' . $slug)) {
            return;
        }

        $args = [
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ];

        wc_create_attribute($args);

        register_taxonomy('pa_' . $slug, ['product'], [
            'labels' => [
                'name' => $name,
            ],
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);
    }

    /**
     * Установка цен товара
     */
    private function set_product_prices(WC_Product $product, array $prices): void {
        $price_type = get_option('wc1c_price_type', 'Розничная');
        
        $selected_price = null;
        foreach ($prices as $price) {
            if ($price['type_name'] === $price_type || empty($price_type)) {
                $selected_price = $price;
                break;
            }
        }

        if (!$selected_price && !empty($prices)) {
            $selected_price = reset($prices);
        }

        if ($selected_price) {
            $product->set_regular_price($selected_price['price']);
            $product->set_price($selected_price['price']);
        }
    }

    /**
     * Синхронизация вариации
     */
    private function sync_variation(array $variation_data, array $offer = []): array {
        $parent_wc_id = $this->get_wc_id($variation_data['parent_id'], 'product');
        
        if (!$parent_wc_id) {
            throw new Exception('Родительский товар не найден');
        }

        $parent_product = wc_get_product($parent_wc_id);
        if (!$parent_product || !$parent_product->is_type('variable')) {
            throw new Exception('Родительский товар не является вариативным');
        }

        $variation_id = $this->get_wc_id($variation_data['id'], 'variation');
        
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                $variation_id = null;
            }
        }

        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            $action = 'updated';
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_wc_id);
            $action = 'created';
        }

        if (!empty($variation_data['sku'])) {
            $existing_id = wc_get_product_id_by_sku($variation_data['sku']);
            if (!$existing_id || $existing_id === $variation->get_id()) {
                $variation->set_sku($variation_data['sku']);
            }
        }

        if (!empty($offer['characteristics'])) {
            $attributes = [];
            foreach ($offer['characteristics'] as $char) {
                $attr_name = 'pa_' . wc_sanitize_taxonomy_name($char['name']);
                $attributes[$attr_name] = $char['value'];
                
                $this->add_variation_attribute_to_parent($parent_product, $char);
            }
            $variation->set_attributes($attributes);
        }

        if (!empty($offer['prices']) && 'yes' === get_option('wc1c_sync_prices', 'yes')) {
            $this->set_product_prices($variation, $offer['prices']);
        }

        if ('yes' === get_option('wc1c_sync_stock', 'yes')) {
            $variation->set_manage_stock(true);
            $stock = $offer['total_stock'] ?? 0;
            $variation->set_stock_quantity($stock);
            $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }

        $variation->set_status('publish');
        $variation->update_meta_data('_1c_guid', $variation_data['id']);

        $var_id = $variation->save();

        $this->save_mapping($variation_data['id'], $var_id, 'variation');

        return ['action' => $action, 'variation_id' => $var_id];
    }

    /**
     * Добавление атрибута вариации к родительскому товару
     */
    private function add_variation_attribute_to_parent(WC_Product_Variable $parent, array $char): void {
        $attr_slug = 'pa_' . wc_sanitize_taxonomy_name($char['name']);
        
        $this->maybe_create_attribute_taxonomy(
            wc_sanitize_taxonomy_name($char['name']),
            $char['name']
        );

        $term = get_term_by('name', $char['value'], $attr_slug);
        if (!$term) {
            $result = wp_insert_term($char['value'], $attr_slug);
            if (!is_wp_error($result)) {
                $term = get_term($result['term_id'], $attr_slug);
            }
        }

        if (!$term) {
            return;
        }

        $attributes = $parent->get_attributes();
        
        if (isset($attributes[$attr_slug])) {
            $existing = $attributes[$attr_slug];
            $options = $existing->get_options();
            if (!in_array($term->term_id, $options)) {
                $options[] = $term->term_id;
                $existing->set_options($options);
            }
            $existing->set_variation(true);
        } else {
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($attr_slug));
            $attribute->set_name($attr_slug);
            $attribute->set_options([$term->term_id]);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $attributes[$attr_slug] = $attribute;
        }

        $parent->set_attributes($attributes);
        $parent->save();
    }

    /**
     * Проверка наличия вариаций у товара
     */
    private function product_has_variations(string $product_id): bool {
        return false;
    }

    /**
     * Обработка удалённого товара
     */
    private function handle_deleted_product(array $product_data): array {
        $wc_product_id = $this->get_wc_id($product_data['id'], 'product');
        
        if ($wc_product_id) {
            $product = wc_get_product($wc_product_id);
            if ($product) {
                $product->set_status('trash');
                $product->save();
            }
        }

        return ['action' => 'deleted', 'product_id' => $wc_product_id];
    }

    /**
     * Синхронизация изображений товара
     */
    private function sync_product_images(int $product_id, array $images): void {
        $upload_dir = wp_upload_dir();
        $exchange_dir = $upload_dir['basedir'] . '/wc-1c-exchange/';
        
        $image_ids = [];
        
        foreach ($images as $index => $image_path) {
            $full_path = $exchange_dir . $image_path;
            
            if (!file_exists($full_path)) {
                continue;
            }

            $attachment_id = $this->get_attachment_by_1c_path($image_path);
            
            if (!$attachment_id) {
                $attachment_id = $this->upload_image($full_path, $product_id);
                
                if ($attachment_id) {
                    update_post_meta($attachment_id, '_1c_image_path', $image_path);
                }
            }

            if ($attachment_id) {
                $image_ids[] = $attachment_id;
            }
        }

        if (!empty($image_ids)) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_image_id($image_ids[0]);
                
                if (count($image_ids) > 1) {
                    $product->set_gallery_image_ids(array_slice($image_ids, 1));
                }
                
                $product->save();
            }
        }
    }

    /**
     * Загрузка изображения в медиабиблиотеку
     */
    private function upload_image(string $file_path, int $parent_id = 0): ?int {
        $file_name = basename($file_path);
        $file_type = wp_check_filetype($file_name);
        
        if (!$file_type['type']) {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $dest_path = $upload_dir['path'] . '/' . $file_name;

        if (!copy($file_path, $dest_path)) {
            return null;
        }

        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => preg_replace('/\.[^.]+$/', '', $file_name),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachment_id = wp_insert_attachment($attachment, $dest_path, $parent_id);
        
        if (is_wp_error($attachment_id)) {
            return null;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attach_data = wp_generate_attachment_metadata($attachment_id, $dest_path);
        wp_update_attachment_metadata($attachment_id, $attach_data);

        return $attachment_id;
    }

    /**
     * Получение вложения по пути из 1С
     */
    private function get_attachment_by_1c_path(string $path): ?int {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_1c_image_path' AND meta_value = %s LIMIT 1",
            $path
        ));

        return $result ? (int)$result : null;
    }

    /**
     * Обновление только цен и остатков
     */
    public function update_offers(array $offers): array {
        $results = [
            'updated' => 0,
            'failed' => 0,
            'not_found' => 0,
            'errors' => [],
        ];

        foreach ($offers as $offer) {
            $product_id = $this->get_wc_id($offer['id'], 'product');
            
            if (!$product_id) {
                $product_id = $this->get_wc_id($offer['id'], 'variation');
            }

            if (!$product_id) {
                $results['not_found']++;
                continue;
            }

            try {
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    $results['not_found']++;
                    continue;
                }

                if (!empty($offer['prices']) && 'yes' === get_option('wc1c_sync_prices', 'yes')) {
                    $this->set_product_prices($product, $offer['prices']);
                }

                if ('yes' === get_option('wc1c_sync_stock', 'yes')) {
                    $stock = $offer['total_stock'] ?? 0;
                    $product->set_stock_quantity($stock);
                    $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
                }

                $product->save();
                $results['updated']++;

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    'Предложение %s: %s',
                    $offer['id'],
                    $e->getMessage()
                );
            }
        }

        return $results;
    }

    /**
     * Получение ID WooCommerce по GUID из 1С
     */
    public function get_wc_id(string $guid, string $type = 'product'): ?int {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_id FROM {$this->mapping_table} WHERE guid_1c = %s AND type = %s LIMIT 1",
            $guid,
            $type
        ));

        return $result ? (int)$result : null;
    }

    /**
     * Сохранение связи ID
     */
    public function save_mapping(string $guid, int $wc_id, string $type = 'product'): void {
        global $wpdb;
        
        $wpdb->replace(
            $this->mapping_table,
            [
                'guid_1c' => $guid,
                'wc_id' => $wc_id,
                'type' => $type,
            ],
            ['%s', '%d', '%s']
        );
    }

    /**
     * Получение GUID 1С по ID WooCommerce
     */
    public function get_1c_guid(int $wc_id, string $type = 'product'): ?string {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT guid_1c FROM {$this->mapping_table} WHERE wc_id = %d AND type = %s LIMIT 1",
            $wc_id,
            $type
        ));
    }
}
