<?php
/**
 * Парсер CommerceML
 *
 * Парсер формата CommerceML 2.x для обмена данными с 1С
 *
 * @package WC_1C_Integration
 */

defined('ABSPATH') || exit;

/**
 * Класс парсера CommerceML
 */
class WC1C_CommerceML_Parser {

    /** @var string Путь к текущему XML-файлу */
    private string $file_path = '';

    /** @var array Данные каталога */
    private array $catalog = [];

    /** @var array Данные предложений */
    private array $offers = [];

    /** @var array Разобранные категории */
    private array $categories = [];

    /** @var array Разобранные свойства (атрибуты) */
    private array $properties = [];

    /** @var array Типы цен */
    private array $price_types = [];

    /** @var array Склады */
    private array $warehouses = [];

    /**
     * Разбор import.xml (структура каталога)
     *
     * @param string $file_path Путь к import.xml
     * @return array Разобранные данные
     */
    public function parse_import(string $file_path): array {
        $this->file_path = $file_path;
        
        if (!file_exists($file_path)) {
            throw new Exception('Файл импорта не найден');
        }

        $xml = $this->load_xml($file_path);
        
        if (!$xml) {
            throw new Exception('Не удалось разобрать XML-файл');
        }

        if (isset($xml->Классификатор)) {
            $this->parse_classifier($xml->Классификатор);
        }

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
     * Разбор offers.xml (цены и остатки)
     *
     * @param string $file_path Путь к offers.xml
     * @return array Разобранные данные предложений
     */
    public function parse_offers(string $file_path): array {
        $this->file_path = $file_path;
        
        if (!file_exists($file_path)) {
            throw new Exception('Файл предложений не найден');
        }

        $xml = $this->load_xml($file_path);
        
        if (!$xml) {
            throw new Exception('Не удалось разобрать XML предложений');
        }

        // offers.xml может содержать свой Классификатор с определениями свойств
        if (isset($xml->Классификатор)) {
            $this->parse_classifier($xml->Классификатор);
        }

        if (isset($xml->ПакетПредложений)) {
            $package = $xml->ПакетПредложений;
            
            if (isset($package->ТипыЦен->ТипЦены)) {
                foreach ($package->ТипыЦен->ТипЦены as $price_type) {
                    $this->price_types[(string)$price_type->Ид] = [
                        'id' => (string)$price_type->Ид,
                        'name' => (string)$price_type->Наименование,
                        'currency' => (string)($price_type->Валюта ?? 'RUB'),
                    ];
                }
            }

            if (isset($package->Склады->Склад)) {
                foreach ($package->Склады->Склад as $warehouse) {
                    $this->warehouses[(string)$warehouse->Ид] = [
                        'id' => (string)$warehouse->Ид,
                        'name' => (string)$warehouse->Наименование,
                    ];
                }
            }

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
     * Загрузка XML-файла с обработкой кодировки
     *
     * @param string $file_path Путь к XML-файлу
     * @return SimpleXMLElement|false
     */
    private function load_xml(string $file_path) {
        libxml_use_internal_errors(true);
        
        $content = file_get_contents($file_path);
        
        if ($content === false || strlen($content) === 0) {
            WC1C_Logger::log("Файл пуст или не читается: {$file_path}", 'error');
            return false;
        }

        // Убираем BOM (UTF-8 BOM = EF BB BF), 1С часто добавляет его
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        WC1C_Logger::log("Загрузка XML: {$file_path}, размер: " . strlen($content), 'info');

        if (preg_match('/encoding=["\']?([^"\'\s\?>]+)/i', $content, $matches)) {
            $encoding = strtoupper($matches[1]);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                $content = preg_replace('/encoding=["\']?[^"\'\s\?>]+/i', 'encoding="UTF-8"', $content);
            }
        }

        // Убираем default namespace из корневого тега (не трогая весь файл)
        $xml_decl_end = strpos($content, '?>');
        $search_from = $xml_decl_end !== false ? $xml_decl_end + 2 : 0;
        $root_end = strpos($content, '>', $search_from);
        if ($root_end !== false) {
            $root_tag = substr($content, 0, $root_end + 1);
            $root_tag_clean = preg_replace('/\s+xmlns\s*=\s*"[^"]*"/', '', $root_tag, 1);
            if ($root_tag_clean !== null && $root_tag_clean !== $root_tag) {
                $content = $root_tag_clean . substr($content, $root_end + 1);
            }
        }

        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_COMPACT);
        
        if (!$xml) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $error_msgs = [];
            foreach ($errors as $err) {
                $error_msgs[] = trim($err->message) . " (строка {$err->line})";
            }
            WC1C_Logger::log('Ошибки разбора XML: ' . implode('; ', $error_msgs), 'error');
            WC1C_Logger::log('Первые 500 символов файла: ' . substr($content, 0, 500), 'error');
            return false;
        }

        return $xml;
    }

    /**
     * Разбор раздела классификатора (категории, свойства)
     *
     * @param SimpleXMLElement $classifier XML-элемент классификатора
     */
    private function parse_classifier(SimpleXMLElement $classifier): void {
        if (isset($classifier->Группы)) {
            $this->parse_categories($classifier->Группы->Группа ?? []);
        }

        if (isset($classifier->Свойства->Свойство)) {
            foreach ($classifier->Свойства->Свойство as $property) {
                $prop_id = (string)$property->Ид;
                
                $this->properties[$prop_id] = [
                    'id' => $prop_id,
                    'name' => (string)$property->Наименование,
                    'type' => (string)($property->ТипЗначений ?? 'Строка'),
                    'values' => [],
                ];

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
     * Рекурсивный разбор категорий
     *
     * @param SimpleXMLElement|array $groups XML-элементы групп
     * @param string $parent_id ID родительской категории
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

            if (isset($group->Группы->Группа)) {
                $this->parse_categories($group->Группы->Группа, $id);
            }
        }
    }

    /**
     * Разбор раздела каталога (товары)
     *
     * @param SimpleXMLElement $catalog XML-элемент каталога
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
     * Разбор одного товара
     *
     * @param SimpleXMLElement $product XML-элемент товара
     */
    private function parse_product(SimpleXMLElement $product): void {
        $id = (string)$product->Ид;
        
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

        if (isset($product->Группы->Ид)) {
            foreach ($product->Группы->Ид as $cat_id) {
                $product_data['categories'][] = (string)$cat_id;
            }
        }

        if (isset($product->Картинка)) {
            foreach ($product->Картинка as $image) {
                $image_path = (string)$image;
                if (!empty($image_path)) {
                    $product_data['images'][] = $image_path;
                }
            }
        }

        if (isset($product->ЗначенияСвойств->ЗначенияСвойства)) {
            foreach ($product->ЗначенияСвойств->ЗначенияСвойства as $prop_value) {
                $prop_id = (string)$prop_value->Ид;
                $value = (string)$prop_value->Значение;
                
                if (!empty($value)) {
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
     * Разбор одного предложения (цена/остаток)
     *
     * @param SimpleXMLElement $offer XML-элемент предложения
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
        } elseif (isset($offer->Количество)) {
            $offer_data['total_stock'] = floatval((string)$offer->Количество);
        }

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
     * Очистка HTML из описания
     *
     * @param string $description Исходное описание
     * @return string Очищенное описание
     */
    private function clean_description(string $description): string {
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
        
        $allowed_tags = '<p><br><strong><b><em><i><ul><ol><li><h2><h3><h4><table><tr><td><th>';
        $description = strip_tags($description, $allowed_tags);
        
        return trim($description);
    }

    /**
     * Генерация orders.xml для выгрузки в 1С
     *
     * @param array $orders Массив данных заказов
     * @return string XML-содержимое
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
            
            $requisites = $doc->addChild('ЗначенияРеквизитов');
            
            $this->add_requisite($requisites, 'Статус заказа', $status_map[$order['status']] ?? $order['status']);
            $this->add_requisite($requisites, 'Дата оплаты', $order['paid_date'] ?? '');
            $this->add_requisite($requisites, 'Способ оплаты', $order['payment_method'] ?? '');
            $this->add_requisite($requisites, 'Способ доставки', $order['shipping_method'] ?? '');
            $this->add_requisite($requisites, 'Итого по доставке', $order['shipping_total'] ?? '0');

            $contractors = $doc->addChild('Контрагенты');
            $contractor = $contractors->addChild('Контрагент');
            
            $contractor->addChild('Ид', $order['customer_id'] ?? '');
            $contractor->addChild('Наименование', $order['billing']['company'] ?: $order['billing']['first_name'] . ' ' . $order['billing']['last_name']);
            $contractor->addChild('Роль', 'Покупатель');
            $contractor->addChild('ПолноеНаименование', $order['billing']['first_name'] . ' ' . $order['billing']['last_name']);
            
            $address = $contractor->addChild('АдресРегистрации');
            $this->add_address_field($address, 'Почтовый индекс', $order['billing']['postcode'] ?? '');
            $this->add_address_field($address, 'Страна', $order['billing']['country'] ?? '');
            $this->add_address_field($address, 'Регион', $order['billing']['state'] ?? '');
            $this->add_address_field($address, 'Город', $order['billing']['city'] ?? '');
            $this->add_address_field($address, 'Улица', $order['billing']['address_1'] ?? '');
            
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

            $items = $doc->addChild('Товары');
            foreach ($order['items'] as $item) {
                $product = $items->addChild('Товар');
                $product->addChild('Ид', $item['product_1c_id'] ?? $item['product_id']);
                $product->addChild('Артикул', $item['sku'] ?? '');
                $product->addChild('Наименование', $item['name']);
                
                $base_unit = $product->addChild('БазоваяЕдиница', 'шт');
                $base_unit->addAttribute('Код', '796');
                $base_unit->addAttribute('НаименованиеПолное', 'Штука');
                
                $product->addChild('ЦенаЗаЕдиницу', $item['price']);
                $product->addChild('Количество', $item['quantity']);
                $product->addChild('Сумма', $item['total']);
                
                if (!empty($item['discount'])) {
                    $discounts = $product->addChild('Скидки');
                    $discount = $discounts->addChild('Скидка');
                    $discount->addChild('Наименование', 'Скидка');
                    $discount->addChild('Сумма', $item['discount']);
                    $discount->addChild('УчтеноВСумме', 'true');
                }
            }
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        
        return $dom->saveXML();
    }

    /**
     * Добавление реквизита в XML
     */
    private function add_requisite(SimpleXMLElement $parent, string $name, string $value): void {
        $req = $parent->addChild('ЗначениеРеквизита');
        $req->addChild('Наименование', $name);
        $req->addChild('Значение', $value);
    }

    /**
     * Добавление адресного поля в XML
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
     * Получить разобранные категории
     */
    public function get_categories(): array {
        return $this->categories;
    }

    /**
     * Получить разобранные свойства
     */
    public function get_properties(): array {
        return $this->properties;
    }

    /**
     * Получить разобранные товары
     */
    public function get_products(): array {
        return $this->catalog;
    }

    /**
     * Получить разобранные предложения
     */
    public function get_offers(): array {
        return $this->offers;
    }
}
