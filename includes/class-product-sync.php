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

    /** @var array ID родительских товаров, у которых есть вариации (заполняется в sync_products) */
    private array $variation_parent_ids = [];

    /** @var array Кэш конвертированных родительских товаров (WC ID → WC_Product_Variable) */
    private array $variable_parents_cache = [];

    /** @var array Кэш ID глобальных WC-атрибутов (taxonomy → int) */
    private array $attribute_id_cache = [];

    /**
     * Конструктор
     */
    public function __construct() {
        global $wpdb;
        $this->mapping_table = $wpdb->prefix . 'wc1c_id_mapping';
    }

    /**
     * Получить ID глобального WC-атрибута по имени таксономии.
     * wc_attribute_taxonomy_id_by_name() может вернуть 0 из-за объектного кэша WC,
     * поэтому используем прямой запрос к БД как fallback.
     */
    private function get_attribute_taxonomy_id(string $taxonomy): int {
        if (isset($this->attribute_id_cache[$taxonomy])) {
            return $this->attribute_id_cache[$taxonomy];
        }

        $id = wc_attribute_taxonomy_id_by_name($taxonomy);

        if (!$id) {
            global $wpdb;
            $slug = str_replace('pa_', '', sanitize_title($taxonomy));
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s",
                $slug
            ));
        }

        if ($id) {
            $this->attribute_id_cache[$taxonomy] = $id;
        }

        return $id;
    }

    /**
     * Транслитерация кириллицы в латиницу для taxonomy slug
     */
    private function transliterate(string $str): string {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'j','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
            'ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'Yo','Ж'=>'Zh',
            'З'=>'Z','И'=>'I','Й'=>'J','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O',
            'П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C',
            'Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
        ];
        return strtr($str, $map);
    }

    /**
     * Безопасный slug для атрибутов (кириллица → латиница)
     */
    private function make_attribute_slug(string $name): string {
        $slug = $this->transliterate($name);
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9_-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        if (empty($slug)) {
            $slug = 'attr-' . substr(md5($name), 0, 8);
        }

        return substr($slug, 0, 28);
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

        // Определяем, какие товары имеют вариации
        $this->variation_parent_ids = [];
        foreach ($products as $product) {
            if ($product['is_variation'] && !empty($product['parent_id'])) {
                $this->variation_parent_ids[$product['parent_id']] = true;
            }
        }

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
     * Установка свойств товара (из import.xml ЗначенияСвойств)
     */
    private function set_product_attributes(WC_Product $product, array $attributes): void {
        $product_attributes = $product->get_attributes();

        foreach ($attributes as $attr) {
            $attr_name = $this->make_attribute_slug($attr['name']);
            $taxonomy = 'pa_' . $attr_name;

            $this->maybe_create_attribute_taxonomy($attr_name, $attr['name']);

            $term = get_term_by('name', $attr['value'], $taxonomy);
            if (!$term) {
                $result = wp_insert_term($attr['value'], $taxonomy);
                if (!is_wp_error($result)) {
                    $term = get_term($result['term_id'], $taxonomy);
                }
            }

            if (!$term) {
                continue;
            }

            $attr_id = $this->get_attribute_taxonomy_id($taxonomy);

            if (isset($product_attributes[$taxonomy])) {
                $existing = $product_attributes[$taxonomy];
                $options = $existing->get_options();
                if (!in_array($term->term_id, $options)) {
                    $options[] = $term->term_id;
                    $existing->set_options($options);
                }
            } else {
                $attribute = new WC_Product_Attribute();
                $attribute->set_id($attr_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options([$term->term_id]);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $product_attributes[$taxonomy] = $attribute;
            }
        }

        if (!empty($product_attributes)) {
            $product->set_attributes($product_attributes);
        }
    }

    /**
     * Создание таксономии свойств при необходимости.
     * Возвращает attribute_id (из wp_woocommerce_attribute_taxonomies).
     */
    private function maybe_create_attribute_taxonomy(string $slug, string $name): int {
        $taxonomy = 'pa_' . $slug;

        if (taxonomy_exists($taxonomy)) {
            return $this->get_attribute_taxonomy_id($taxonomy);
        }

        $result = wc_create_attribute([
            'name' => $name,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false,
        ]);

        if (is_wp_error($result)) {
            WC1C_Logger::log("Ошибка создания атрибута '{$name}' ({$slug}): " . $result->get_error_message(), 'error');
            return 0;
        }

        // Принудительная очистка кэша атрибутов WC
        delete_transient('wc_attribute_taxonomies');
        wp_cache_delete('attribute_taxonomies', 'woocommerce-attributes');
        unset($this->attribute_id_cache[$taxonomy]);

        register_taxonomy($taxonomy, ['product'], [
            'labels' => ['name' => $name],
            'hierarchical' => false,
            'show_ui' => false,
            'query_var' => true,
            'rewrite' => false,
        ]);

        $attr_id = $this->get_attribute_taxonomy_id($taxonomy);
        WC1C_Logger::log("Создан атрибут: '{$name}' → {$taxonomy}, attr_id={$attr_id}", 'info');

        return $attr_id;
    }

    /**
     * Установка цен товара
     */
    private function set_product_prices(WC_Product $product, array $prices): void {
        $price_type = get_option('wc1c_price_type', 'Розничная');
        $sale_price_type = get_option('wc1c_sale_price_type', '');
        
        $regular_price = null;
        $sale_price = null;

        foreach ($prices as $price) {
            if ($price['type_name'] === $price_type || empty($price_type)) {
                $regular_price = $price['price'];
            }
            if (!empty($sale_price_type) && $price['type_name'] === $sale_price_type) {
                $sale_price = $price['price'];
            }
        }

        if ($regular_price === null && !empty($prices)) {
            $first = reset($prices);
            $regular_price = $first['price'];
        }

        if ($regular_price !== null) {
            $product->set_regular_price($regular_price);

            if ($sale_price !== null && $sale_price > 0 && $sale_price < $regular_price) {
                $product->set_sale_price($sale_price);
                $product->set_price($sale_price);
            } else {
                $product->set_sale_price('');
                $product->set_price($regular_price);
            }
        }
    }

    /**
     * Синхронизация вариации (из import.xml, товар с # в ID)
     */
    private function sync_variation(array $variation_data, array $offer = []): array {
        $parent_wc_id = $this->get_wc_id($variation_data['parent_id'], 'product');

        if (!$parent_wc_id) {
            throw new Exception('Родительский товар не найден');
        }

        $parent_product = $this->ensure_variable_parent($parent_wc_id);
        if (!$parent_product) {
            throw new Exception('Родительский товар не найден в WooCommerce');
        }

        $variation_id = $this->get_wc_id($variation_data['id'], 'variation');

        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->exists()) {
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
            $this->add_variation_attributes_to_parent($parent_product, $offer['characteristics']);
            $attrs = $this->build_variation_attributes($parent_product, $offer['characteristics']);
            $variation->set_attributes($attrs);
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
     * Собрать массив атрибутов для вариации, сверяясь с родителем.
     *
     * Копирует подход рабочего плагина (setVariationAttributes):
     * — ключ = sanitize_title(parent_attribute->get_name())
     * — значение = term->slug (для таксономий) или sanitize_title (fallback)
     * — атрибут должен существовать на родителе и иметь get_variation()=true
     */
    private function build_variation_attributes(WC_Product $parent, array $characteristics): array {
        $parent_attributes = $parent->get_attributes();
        $attrs = [];

        foreach ($characteristics as $char) {
            $taxonomy = 'pa_' . $this->make_attribute_slug($char['name']);

            if (!isset($parent_attributes[$taxonomy])) {
                WC1C_Logger::log("Атрибут {$taxonomy} не найден на родителе #{$parent->get_id()}, пропуск", 'warning');
                continue;
            }

            $parent_attr = $parent_attributes[$taxonomy];
            if (!$parent_attr->get_variation()) {
                WC1C_Logger::log("Атрибут {$taxonomy} на родителе #{$parent->get_id()} не отмечен как вариативный", 'warning');
                continue;
            }

            $attribute_key = sanitize_title($parent_attr->get_name());

            if ($parent_attr->is_taxonomy()) {
                $term = get_term_by('name', $char['value'], $taxonomy);
                $value = ($term && !is_wp_error($term)) ? $term->slug : sanitize_title($char['value']);
            } else {
                $value = $char['value'];
            }

            $attrs[$attribute_key] = $value;
        }

        return $attrs;
    }

    /**
     * Добавление атрибутов вариации к родительскому товару.
     *
     * По аналогии с рабочим плагином wc1c-main-trunk:
     * — всегда сохраняем родителя (гарантия set_variation=1)
     * — явный wp_set_object_terms для привязки термов
     * — fallback получения attr_id через прямой запрос к БД
     */
    private function add_variation_attributes_to_parent(WC_Product $parent, array $characteristics): void {
        $attributes = $parent->get_attributes();
        $parent_id = $parent->get_id();

        foreach ($characteristics as $char) {
            $slug = $this->make_attribute_slug($char['name']);
            $taxonomy = 'pa_' . $slug;

            $attr_id = $this->maybe_create_attribute_taxonomy($slug, $char['name']);

            $term = get_term_by('name', $char['value'], $taxonomy);
            if (!$term) {
                $result = wp_insert_term($char['value'], $taxonomy);
                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'term_exists') {
                        $term = get_term((int) $result->get_error_data(), $taxonomy);
                    } else {
                        WC1C_Logger::log("wp_insert_term('{$char['value']}', '{$taxonomy}'): " . $result->get_error_message(), 'warning');
                        continue;
                    }
                } else {
                    $term = get_term($result['term_id'], $taxonomy);
                }
            }

            if (!$term || is_wp_error($term)) {
                continue;
            }

            // Привязываем терм к родительскому товару (append=true)
            wp_set_object_terms($parent_id, $term->term_id, $taxonomy, true);

            if (isset($attributes[$taxonomy])) {
                $existing = $attributes[$taxonomy];
                $options = $existing->get_options();
                if (!in_array($term->term_id, $options)) {
                    $options[] = $term->term_id;
                    $existing->set_options($options);
                }
                if (!$existing->get_variation()) {
                    $existing->set_variation(true);
                }
                if (!$attr_id) {
                    $attr_id = $this->get_attribute_taxonomy_id($taxonomy);
                }
                if ($existing->get_id() === 0 && $attr_id) {
                    $existing->set_id($attr_id);
                }
            } else {
                if (!$attr_id) {
                    $attr_id = $this->get_attribute_taxonomy_id($taxonomy);
                }
                $attribute = new WC_Product_Attribute();
                $attribute->set_id($attr_id);
                $attribute->set_name($taxonomy);
                $attribute->set_options([$term->term_id]);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[$taxonomy] = $attribute;
            }
        }

        $parent->set_attributes($attributes);
        $parent->save();
    }

    /**
     * Проверка наличия вариаций у товара
     */
    private function product_has_variations(string $product_id): bool {
        return isset($this->variation_parent_ids[$product_id]);
    }

    /**
     * Получить родительский товар как WC_Product_Variable, конвертируя Simple если нужно.
     *
     * ВАЖНО: wc_get_product() после wp_set_object_terms может вернуть WC_Product_Simple из кэша.
     * При вызове save() на WC_Product_Simple WooCommerce откатывает product_type обратно в simple.
     * Поэтому нужно создавать new WC_Product_Variable напрямую.
     */
    private function ensure_variable_parent(int $wc_id): ?WC_Product_Variable {
        if (isset($this->variable_parents_cache[$wc_id])) {
            return $this->variable_parents_cache[$wc_id];
        }

        $product = wc_get_product($wc_id);
        if (!$product) {
            return null;
        }

        if ($product->is_type('variable')) {
            $this->variable_parents_cache[$wc_id] = $product;
            return $product;
        }

        // Конвертация: создаём объект WC_Product_Variable напрямую, не через фабрику
        WC1C_Logger::log("Конвертация Simple→Variable: товар #{$wc_id} «{$product->get_name()}»", 'info');

        $variable = new WC_Product_Variable($wc_id);
        $variable->save();

        // Очищаем все кэши
        clean_post_cache($wc_id);
        wc_delete_product_transients($wc_id);

        $this->variable_parents_cache[$wc_id] = $variable;
        return $variable;
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
     * Обновление цен/остатков и создание вариаций из offers.xml
     *
     * В реальном обмене 1С вариации определяются именно в offers.xml:
     * ID имеет вид "parent_guid#variation_guid", плюс ХарактеристикиТовара.
     */
    public function update_offers(array $offers): array {
        $this->variable_parents_cache = [];
        $this->attribute_id_cache = [];

        $results = [
            'updated' => 0,
            'created' => 0,
            'failed' => 0,
            'not_found' => 0,
            'errors' => [],
        ];

        foreach ($offers as $offer) {
            try {
                $is_variation = strpos($offer['id'], '#') !== false;

                if ($is_variation) {
                    $this->process_variation_offer($offer, $results);
                } else {
                    $this->process_simple_offer($offer, $results);
                }
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
     * Обработка простого предложения (без вариаций)
     */
    private function process_simple_offer(array $offer, array &$results): void {
        $product_id = $this->get_wc_id($offer['id'], 'product');

        if (!$product_id) {
            $results['not_found']++;
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $results['not_found']++;
            return;
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
    }

    /**
     * Обработка предложения-вариации (ID содержит #)
     */
    private function process_variation_offer(array $offer, array &$results): void {
        $parts = explode('#', $offer['id'], 2);
        $parent_guid = $parts[0];
        $variation_guid = $offer['id'];

        $parent_wc_id = $this->get_wc_id($parent_guid, 'product');
        if (!$parent_wc_id) {
            $results['not_found']++;
            return;
        }

        $parent_product = $this->ensure_variable_parent($parent_wc_id);
        if (!$parent_product) {
            $results['not_found']++;
            return;
        }

        // Регистрируем атрибуты характеристик на родителе (до создания вариации)
        if (!empty($offer['characteristics'])) {
            $this->add_variation_attributes_to_parent($parent_product, $offer['characteristics']);
        }

        // Ищем или создаём вариацию
        $variation_wc_id = $this->get_wc_id($variation_guid, 'variation');
        $variation = null;
        $action = 'updated';

        if ($variation_wc_id) {
            $variation = wc_get_product($variation_wc_id);
            if (!$variation || !$variation->exists()) {
                $variation = null;
            }
        }

        if (!$variation) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($parent_wc_id);
            $action = 'created';
        }

        if (!empty($offer['sku'])) {
            $existing_id = wc_get_product_id_by_sku($offer['sku']);
            if (!$existing_id || $existing_id === $variation->get_id()) {
                $variation->set_sku($offer['sku']);
            }
        }

        // Атрибуты вариации — по образцу рабочего плагина (setVariationAttributes)
        if (!empty($offer['characteristics'])) {
            $attrs = $this->build_variation_attributes($parent_product, $offer['characteristics']);
            $variation->set_attributes($attrs);
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
        $variation->update_meta_data('_1c_guid', $variation_guid);

        $var_id = $variation->save();
        $this->save_mapping($variation_guid, $var_id, 'variation');

        if ($action === 'created') {
            $results['created']++;
        } else {
            $results['updated']++;
        }
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
