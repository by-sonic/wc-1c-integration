<?php
/**
 * CommerceML Parser
 *
 * Парсер формата CommerceML 2.x для обмена данными с 1С
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * CommerceML Parser class
 */
class WC1C_CommerceML_Parser {

    /**
     * Current XML file path
     */
    private string $file_path = '';

    /**
     * Parsed catalog data
     */
    private array $catalog = [];

    /**
     * Parsed offers data
     */
    private array $offers = [];

    /**
     * Parsed categories
     */
    private array $categories = [];

    /**
     * Parsed properties (attributes)
     */
    private array $properties = [];

    /**
     * Price types
     */
    private array $price_types = [];

    /**
     * Warehouses
     */
    private array $warehouses = [];

    /**
     * Parse import.xml (catalog structure)
     *
     * @param string $file_path Path to import.xml
     * @return array Parsed data
     */
    public function parse_import(string $file_path): array {
        $this->file_path = $file_path;
        
        if (!file_exists($file_path)) {
            throw new Exception(__('Import file not found', 'wc-1c-integration'));
        }

        $xml = $this->load_xml($file_path);
        
        if (!$xml) {
            throw new Exception(__('Failed to parse XML file', 'wc-1c-integration'));
        }

        // Parse classifier (categories, properties)
        if (isset($xml->Классификатор)) {
            $this->parse_classifier($xml->Классификатор);
        }

        // Parse catalog (products)
        if (isset($xml->Каталог)) {
            $this->parse_catalog($xml->Каталог);
        }

        return [
            'categories' => $this->categories,
            'properties' => $this->properties,
            'products' => $this->catalog,
        ];
    }

    /**
     * Parse offers.xml (prices and stock)
     *
     * @param string $file_path Path to offers.xml
     * @return array Parsed offers data
     */
    public function parse_offers(string $file_path): array {
        $this->file_path = $file_path;
        
        if (!file_exists($file_path)) {
            throw new Exception(__('Offers file not found', 'wc-1c-integration'));
        }

        $xml = $this->load_xml($file_path);
        
        if (!$xml) {
            throw new Exception(__('Failed to parse offers XML', 'wc-1c-integration'));
        }

        // Parse package offers
        if (isset($xml->ПакетПредложений)) {
            $package = $xml->ПакетПредложений;
            
            // Parse price types
            if (isset($package->ТипыЦен->ТипЦены)) {
                foreach ($package->ТипыЦен->ТипЦены as $price_type) {
                    $this->price_types[(string)$price_type->Ид] = [
                        'id' => (string)$price_type->Ид,
                        'name' => (string)$price_type->Наименование,
                        'currency' => (string)($price_type->Валюта ?? 'RUB'),
                    ];
                }
            }

            // Parse warehouses
            if (isset($package->Склады->Склад)) {
                foreach ($package->Склады->Склад as $warehouse) {
                    $this->warehouses[(string)$warehouse->Ид] = [
                        'id' => (string)$warehouse->Ид,
                        'name' => (string)$warehouse->Наименование,
                    ];
                }
            }

            // Parse offers
            if (isset($package->Предложения->Предложение)) {
                foreach ($package->Предложения->Предложение as $offer) {
                    $this->parse_offer($offer);
                }
            }
        }

        return [
            'price_types' => $this->price_types,
            'warehouses' => $this->warehouses,
            'offers' => $this->offers,
        ];
    }

    /**
     * Load XML file with proper encoding handling
     *
     * @param string $file_path Path to XML file
     * @return SimpleXMLElement|false
     */
    private function load_xml(string $file_path) {
        libxml_use_internal_errors(true);
        
        $content = file_get_contents($file_path);
        
        // Handle different encodings
        if (preg_match('/encoding=["\']?([^"\'\s\?>]+)/i', $content, $matches)) {
            $encoding = strtoupper($matches[1]);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                $content = preg_replace('/encoding=["\']?[^"\'\s\?>]+/i', 'encoding="UTF-8"', $content);
            }
        }

        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        
        if (!$xml) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            WC1C_Logger::log('XML parse errors: ' . print_r($errors, true), 'error');
            return false;
        }

        return $xml;
    }

    /**
     * Parse classifier section (categories, properties)
     *
     * @param SimpleXMLElement $classifier Classifier XML element
     */
    private function parse_classifier(SimpleXMLElement $classifier): void {
        // Parse categories (groups)
        if (isset($classifier->Группы)) {
            $this->parse_categories($classifier->Группы->Группа ?? []);
        }

        // Parse properties (attributes)
        if (isset($classifier->Свойства->Свойство)) {
            foreach ($classifier->Свойства->Свойство as $property) {
                $prop_id = (string)$property->Ид;
                
                $this->properties[$prop_id] = [
                    'id' => $prop_id,
                    'name' => (string)$property->Наименование,
                    'type' => (string)($property->ТипЗначений ?? 'Строка'),
                    'values' => [],
                ];

                // Parse predefined values
                if (isset($property->ВариантыЗначений->Справочник)) {
                    foreach ($property->ВариантыЗначений->Справочник as $value) {
                        $value_id = (string)$value->ИдЗначения;
                        $this->properties[$prop_id]['values'][$value_id] = (string)$value->Значение;
                    }
                }
            }
        }
    }

    /**
     * Parse categories recursively
     *
     * @param SimpleXMLElement|array $groups Groups XML elements
     * @param string $parent_id Parent category ID
     */
    private function parse_categories($groups, string $parent_id = ''): void {
        foreach ($groups as $group) {
            $id = (string)$group->Ид;
            
            $this->categories[$id] = [
                'id' => $id,
                'name' => (string)$group->Наименование,
                'parent_id' => $parent_id,
                'description' => (string)($group->Описание ?? ''),
            ];

            // Parse nested categories
            if (isset($group->Группы->Группа)) {
                $this->parse_categories($group->Группы->Группа, $id);
            }
        }
    }

    /**
     * Parse catalog section (products)
     *
     * @param SimpleXMLElement $catalog Catalog XML element
     */
    private function parse_catalog(SimpleXMLElement $catalog): void {
        if (!isset($catalog->Товары->Товар)) {
            return;
        }

        foreach ($catalog->Товары->Товар as $product) {
            $this->parse_product($product);
        }
    }

    /**
     * Parse single product
     *
     * @param SimpleXMLElement $product Product XML element
     */
    private function parse_product(SimpleXMLElement $product): void {
        $id = (string)$product->Ид;
        
        // Check if this is a variation (contains #)
        $is_variation = strpos($id, '#') !== false;
        $parent_id = $is_variation ? explode('#', $id)[0] : '';

        $product_data = [
            'id' => $id,
            'sku' => (string)($product->Артикул ?? ''),
            'name' => (string)$product->Наименование,
            'description' => $this->clean_description((string)($product->Описание ?? '')),
            'short_description' => (string)($product->Описание ?? ''),
            'barcode' => (string)($product->Штрихкод ?? ''),
            'unit' => (string)($product->БазоваяЕдиница ?? 'шт'),
            'categories' => [],
            'images' => [],
            'attributes' => [],
            'is_variation' => $is_variation,
            'parent_id' => $parent_id,
            'status' => 'active',
        ];

        // Parse categories
        if (isset($product->Группы->Ид)) {
            foreach ($product->Группы->Ид as $cat_id) {
                $product_data['categories'][] = (string)$cat_id;
            }
        }

        // Parse images
        if (isset($product->Картинка)) {
            foreach ($product->Картинка as $image) {
                $image_path = (string)$image;
                if (!empty($image_path)) {
                    $product_data['images'][] = $image_path;
                }
            }
        }

        // Parse properties/attributes
        if (isset($product->ЗначенияСвойств->ЗначенияСвойства)) {
            foreach ($product->ЗначенияСвойств->ЗначенияСвойства as $prop_value) {
                $prop_id = (string)$prop_value->Ид;
                $value = (string)$prop_value->Значение;
                
                if (!empty($value)) {
                    // Resolve value from dictionary if exists
                    if (isset($this->properties[$prop_id]['values'][$value])) {
                        $value = $this->properties[$prop_id]['values'][$value];
                    }
                    
                    $product_data['attributes'][$prop_id] = [
                        'id' => $prop_id,
                        'name' => $this->properties[$prop_id]['name'] ?? $prop_id,
                        'value' => $value,
                    ];
                }
            }
        }

        // Parse requisites (additional fields)
        if (isset($product->ЗначенияРеквизитов->ЗначениеРеквизита)) {
            foreach ($product->ЗначенияРеквизитов->ЗначениеРеквизита as $requisite) {
                $name = (string)$requisite->Наименование;
                $value = (string)$requisite->Значение;
                
                switch ($name) {
                    case 'Вес':
                        $product_data['weight'] = floatval($value);
                        break;
                    case 'ТипНоменклатуры':
                        $product_data['product_type'] = $value;
                        break;
                    case 'ПометкаУдаления':
                        if ($value === 'true' || $value === '1') {
                            $product_data['status'] = 'deleted';
                        }
                        break;
                    case 'Производитель':
                        $product_data['manufacturer'] = $value;
                        break;
                }
            }
        }

        $this->catalog[$id] = $product_data;
    }

    /**
     * Parse single offer (price/stock)
     *
     * @param SimpleXMLElement $offer Offer XML element
     */
    private function parse_offer(SimpleXMLElement $offer): void {
        $id = (string)$offer->Ид;
        
        $offer_data = [
            'id' => $id,
            'sku' => (string)($offer->Артикул ?? ''),
            'name' => (string)$offer->Наименование,
            'prices' => [],
            'stock' => [],
            'total_stock' => 0,
            'characteristics' => [],
        ];

        // Parse prices
        if (isset($offer->Цены->Цена)) {
            foreach ($offer->Цены->Цена as $price) {
                $price_type_id = (string)$price->ИдТипаЦены;
                $price_value = floatval((string)$price->ЦенаЗаЕдиницу);
                
                $offer_data['prices'][$price_type_id] = [
                    'type_id' => $price_type_id,
                    'type_name' => $this->price_types[$price_type_id]['name'] ?? '',
                    'price' => $price_value,
                    'currency' => (string)($price->Валюта ?? 'RUB'),
                    'unit' => (string)($price->Единица ?? ''),
                ];
            }
        }

        // Parse stock (quantity)
        if (isset($offer->Количество)) {
            $offer_data['total_stock'] = floatval((string)$offer->Количество);
        }

        // Parse stock by warehouse
        if (isset($offer->Склад)) {
            foreach ($offer->Склад as $stock) {
                $attrs = $stock->attributes();
                $warehouse_id = (string)$attrs['ИдСклада'];
                $quantity = floatval((string)$attrs['КоличествоНаСкладе']);
                
                $offer_data['stock'][$warehouse_id] = [
                    'warehouse_id' => $warehouse_id,
                    'warehouse_name' => $this->warehouses[$warehouse_id]['name'] ?? '',
                    'quantity' => $quantity,
                ];
                
                $offer_data['total_stock'] += $quantity;
            }
        }

        // Parse characteristics (for variations)
        if (isset($offer->ХарактеристикиТовара->ХарактеристикаТовара)) {
            foreach ($offer->ХарактеристикиТовара->ХарактеристикаТовара as $char) {
                $offer_data['characteristics'][] = [
                    'id' => (string)$char->Ид,
                    'name' => (string)$char->Наименование,
                    'value' => (string)$char->Значение,
                ];
            }
        }

        $this->offers[$id] = $offer_data;
    }

    /**
     * Clean HTML from description
     *
     * @param string $description Raw description
     * @return string Cleaned description
     */
    private function clean_description(string $description): string {
        // Decode HTML entities
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
        
        // Allow certain HTML tags
        $allowed_tags = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><table><tr><td><th>';
        $description = strip_tags($description, $allowed_tags);
        
        return trim($description);
    }

    /**
     * Generate orders.xml for export to 1C
     *
     * @param array $orders Array of order data
     * @return string XML content
     */
    public function generate_orders_xml(array $orders): string {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация></КоммерческаяИнформация>');
        
        $xml->addAttribute('ВерсияСхемы', '2.10');
        $xml->addAttribute('ДатаФормирования', date('Y-m-d\TH:i:s'));

        foreach ($orders as $order) {
            $doc = $xml->addChild('Документ');
            
            $doc->addChild('Ид', $order['id']);
            $doc->addChild('Номер', $order['number']);
            $doc->addChild('Дата', $order['date']);
            $doc->addChild('Время', $order['time']);
            $doc->addChild('ХозОперация', 'Заказ товара');
            $doc->addChild('Роль', 'Продавец');
            $doc->addChild('Валюта', $order['currency'] ?? 'RUB');
            $doc->addChild('Курс', '1');
            $doc->addChild('Сумма', $order['total']);

            // Order status mapping
            $status_map = [
                'pending' => 'Новый',
                'processing' => 'В обработке',
                'on-hold' => 'На удержании',
                'completed' => 'Выполнен',
                'cancelled' => 'Отменен',
                'refunded' => 'Возврат',
                'failed' => 'Ошибка',
            ];
            
            $doc->addChild('Комментарий', $order['customer_note'] ?? '');
            
            // Add requisites
            $requisites = $doc->addChild('ЗначенияРеквизитов');
            
            $this->add_requisite($requisites, 'Статус заказа', $status_map[$order['status']] ?? $order['status']);
            $this->add_requisite($requisites, 'Дата оплаты', $order['paid_date'] ?? '');
            $this->add_requisite($requisites, 'Способ оплаты', $order['payment_method'] ?? '');
            $this->add_requisite($requisites, 'Способ доставки', $order['shipping_method'] ?? '');
            $this->add_requisite($requisites, 'Итого по доставке', $order['shipping_total'] ?? '0');

            // Customer info
            $contractors = $doc->addChild('Контрагенты');
            $contractor = $contractors->addChild('Контрагент');
            
            $contractor->addChild('Ид', $order['customer_id'] ?? '');
            $contractor->addChild('Наименование', $order['billing']['company'] ?: $order['billing']['first_name'] . ' ' . $order['billing']['last_name']);
            $contractor->addChild('Роль', 'Покупатель');
            $contractor->addChild('ПолноеНаименование', $order['billing']['first_name'] . ' ' . $order['billing']['last_name']);
            
            // Customer address
            $address = $contractor->addChild('АдресРегистрации');
            $this->add_address_field($address, 'Почтовый индекс', $order['billing']['postcode'] ?? '');
            $this->add_address_field($address, 'Страна', $order['billing']['country'] ?? '');
            $this->add_address_field($address, 'Регион', $order['billing']['state'] ?? '');
            $this->add_address_field($address, 'Город', $order['billing']['city'] ?? '');
            $this->add_address_field($address, 'Улица', $order['billing']['address_1'] ?? '');
            
            // Customer contacts
            $contacts = $contractor->addChild('Контакты');
            if (!empty($order['billing']['email'])) {
                $contact = $contacts->addChild('Контакт');
                $contact->addChild('Тип', 'Почта');
                $contact->addChild('Значение', $order['billing']['email']);
            }
            if (!empty($order['billing']['phone'])) {
                $contact = $contacts->addChild('Контакт');
                $contact->addChild('Тип', 'Телефон');
                $contact->addChild('Значение', $order['billing']['phone']);
            }

            // Order items
            $items = $doc->addChild('Товары');
            foreach ($order['items'] as $item) {
                $product = $items->addChild('Товар');
                $product->addChild('Ид', $item['product_1c_id'] ?? $item['product_id']);
                $product->addChild('Артикул', $item['sku'] ?? '');
                $product->addChild('Наименование', $item['name']);
                
                $base_unit = $product->addChild('БазоваяЕдиница', 'шт');
                $base_unit->addAttribute('Код', '796');
                $base_unit->addAttribute('НаsименованиеПолное', 'Штука');
                
                $product->addChild('ЦенаЗаЕдиницу', $item['price']);
                $product->addChild('Количество', $item['quantity']);
                $product->addChild('Сумма', $item['total']);
                
                // Item discounts
                if (!empty($item['discount'])) {
                    $discounts = $product->addChild('Скидки');
                    $discount = $discounts->addChild('Скидка');
                    $discount->addChild('Наименование', 'Скидка');
                    $discount->addChild('Сумма', $item['discount']);
                    $discount->addChild('УчssтьВСумме', 'true');
                }
            }
        }

        // Format output
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        
        return $dom->saveXML();
    }

    /**
     * Add requisite to XML
     */
    private function add_requisite(SimpleXMLElement $parent, string $name, string $value): void {
        $req = $parent->addChild('ЗначениеРеквизита');
        $req->addChild('Наименование', $name);
        $req->addChild('Значение', $value);
    }

    /**
     * Add address field to XML
     */
    private function add_address_field(SimpleXMLElement $parent, string $type, string $value): void {
        if (empty($value)) {
            return;
        }
        $field = $parent->addChild('АдресноеПоле');
        $field->addChild('Тип', $type);
        $field->addChild('Значение', $value);
    }

    /**
     * Get parsed categories
     */
    public function get_categories(): array {
        return $this->categories;
    }

    /**
     * Get parsed properties
     */
    public function get_properties(): array {
        return $this->properties;
    }

    /**
     * Get parsed products
     */
    public function get_products(): array {
        return $this->catalog;
    }

    /**
     * Get parsed offers
     */
    public function get_offers(): array {
        return $this->offers;
    }
}
