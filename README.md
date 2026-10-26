<!-- SUPPORT-START -->
<p align="center">
  <b>💛 Поддержать проект</b> · <b>USDT</b> (Tether) в сети <b>TRC20</b>:<br/>
  <code>TEHHyPSeZ7t5vfVJmjtEBkqJdpfcrm1wqg</code>
</p>
<!-- SUPPORT-END -->

<div align="center">

# WooCommerce 1C Integration

### Полная двусторонняя интеграция WooCommerce с 1С:Предприятие

[![WordPress](https://img.shields.io/badge/WordPress-5.8+-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0+-96588A?style=for-the-badge&logo=woocommerce&logoColor=white)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPL%20v2+-red?style=for-the-badge)](LICENSE)
[![Telegram](https://img.shields.io/badge/Telegram-@by__sonicx-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/by_sonicx)

**Лучшее бесплатное решение для синхронизации WooCommerce и 1С через CommerceML 2.x**

[Документация](#-установка) · [Возможности](#-возможности) · [Поддержка](https://t.me/by_sonicx)

---

</div>

## Проблема

Интеграция интернет-магазина на WooCommerce с 1С — головная боль для владельцев бизнеса:
- Платные решения стоят от 15 000 ₽
- Бесплатные плагины не поддерживаются и не работают
- Ручная синхронизация занимает часы

## Решение

**WooCommerce 1C Integration** — полностью бесплатный плагин с открытым кодом, который автоматизирует обмен данными между вашим интернет-магазином и 1С.

---

## ✨ Возможности

<table>
<tr>
<td width="50%">

### 📦 Импорт из 1С
- Товары с описаниями и артикулами
- Категории с иерархией
- Атрибуты и свойства
- Изображения товаров
- Вариации (размер, цвет и т.д.)
- Штрихкоды

</td>
<td width="50%">

### 💰 Цены и остатки
- Несколько типов цен
- Остатки по складам
- Автообновление статуса
- Поддержка валют

</td>
</tr>
<tr>
<td width="50%">

### 📋 Экспорт заказов
- Автоматическая выгрузка
- Данные покупателей
- Товары со скидками
- Способы оплаты/доставки

</td>
<td width="50%">

### 🔄 Синхронизация
- Двусторонний обмен
- Обновление статусов
- Номера отслеживания
- Документы 1С

</td>
</tr>
</table>

---

## 🚀 Быстрый старт

### Требования

| Компонент | Версия |
|-----------|--------|
| WordPress | 5.8+ |
| WooCommerce | 5.0+ |
| PHP | 7.4+ |
| 1С:Предприятие | 8.x |

### Установка

#### Способ 1: Через WordPress

```
1. Скачайте ZIP-архив плагина
2. WordPress Admin → Плагины → Добавить новый → Загрузить
3. Выберите архив и установите
4. Активируйте плагин
```

#### Способ 2: Через FTP

```bash
# Загрузите папку плагина в:
/wp-content/plugins/wc-1c-integration/
```

#### Способ 3: Git Clone

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/by-sonic/wc-1c-integration.git
```

---

## ⚙️ Настройка

### Шаг 1: Настройка WordPress

1. Перейдите в **WooCommerce → 1C Integration**
2. Укажите логин и пароль для аутентификации
3. Скопируйте **Exchange URL**

### Шаг 2: Настройка 1С

```
1. Откройте конфигурацию 1С
2. Настройки обмена с сайтом
3. Вставьте Exchange URL
4. Введите логин/пароль
5. Проверьте соединение ✓
```

### Шаг 3: Выберите что синхронизировать

| Опция | Описание |
|-------|----------|
| `Категории` | Группы товаров из 1С |
| `Атрибуты` | Свойства товаров |
| `Изображения` | Фото товаров |
| `Цены` | Типы цен (розница, опт) |
| `Остатки` | Количество на складах |

---

## 📡 API Reference

### Endpoint

```
https://your-site.com/1c-exchange/
```

### Параметры

| Параметр | Значение | Описание |
|----------|----------|----------|
| `type` | `catalog` | Обмен каталогом товаров |
| `type` | `sale` | Обмен заказами |
| `mode` | `checkauth` | Проверка авторизации |
| `mode` | `init` | Инициализация сессии |
| `mode` | `file` | Загрузка файла |
| `mode` | `import` | Импорт данных |
| `mode` | `query` | Запрос заказов |

### CommerceML файлы

```
import.xml   → Каталог товаров
offers.xml   → Цены и остатки  
orders.xml   → Заказы
```

---

## 🔧 Для разработчиков

### Хуки

```php
// После импорта товара
do_action('wc1c_product_imported', $product_id, $product_data);

// После синхронизации категории
do_action('wc1c_category_synced', $term_id, $category_data);

// После экспорта заказа
do_action('wc1c_order_exported', $order_id);
```

### Фильтры

```php
// Модификация данных товара
add_filter('wc1c_product_data', function($data, $raw) {
    $data['name'] = mb_strtoupper($data['name']);
    return $data;
}, 10, 2);

// Фильтрация заказов
add_filter('wc1c_orders_for_export', function($orders) {
    return array_filter($orders, fn($o) => $o['total'] > 1000);
});
```

---

## 🐛 Troubleshooting

<details>
<summary><b>Ошибка 401 Unauthorized</b></summary>

Проверьте:
1. Логин и пароль в настройках плагина
2. `.htaccess` не блокирует Authorization header
3. Нет конфликта с BasicAuth на сервере

</details>

<details>
<summary><b>Файлы не загружаются</b></summary>

Увеличьте лимиты PHP:

```ini
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
memory_limit = 256M
```

</details>

<details>
<summary><b>Проблемы с кодировкой</b></summary>

Плагин автоматически конвертирует windows-1251 → UTF-8.

Если не помогло:
1. Проверьте кодировку БД WordPress
2. Убедитесь что 1С отправляет UTF-8 или windows-1251

</details>

<details>
<summary><b>Товары не импортируются</b></summary>

1. Включите Debug Mode в настройках
2. Смотрите логи: WooCommerce → 1C Integration → Logs
3. Проверьте валидность CommerceML файлов

</details>

---

## 📊 Сравнение с конкурентами

| Функция | WC 1C Integration | Конкурент A | Конкурент B |
|---------|:-----------------:|:-----------:|:-----------:|
| Цена | **Бесплатно** | 15 000 ₽ | 25 000 ₽ |
| Импорт товаров | ✅ | ✅ | ✅ |
| Экспорт заказов | ✅ | ✅ | ✅ |
| Вариации | ✅ | ❌ | ✅ |
| Открытый код | ✅ | ❌ | ❌ |
| Поддержка | ✅ Telegram | Email | Email |
| Обновления | ✅ Бесплатно | Платно | Платно |

---

## 🤝 Contributing

Мы открыты для контрибьютов! 

```bash
# 1. Форкните репозиторий
# 2. Создайте ветку
git checkout -b feature/amazing-feature

# 3. Закоммитьте изменения
git commit -m 'Add amazing feature'

# 4. Запушьте
git push origin feature/amazing-feature

# 5. Откройте Pull Request
```

---

## 📝 Changelog

### v1.0.0 (2026-02-05)
- Первый релиз
- Полная поддержка CommerceML 2.x
- Импорт каталога из 1С
- Синхронизация цен и остатков
- Экспорт заказов в 1С
- Админ-панель настроек
- Логирование операций

---

## 📜 Лицензия

Распространяется под лицензией **GPL v2 или новее**.

Это означает:
- ✅ Бесплатное использование
- ✅ Модификация кода
- ✅ Распространение
- ✅ Коммерческое использование

---

## 👨‍💻 Автор

<div align="center">

**by sonic**

[![Telegram](https://img.shields.io/badge/Telegram-@by__sonicx-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white)](https://t.me/by_sonicx)

Если плагин был полезен — поставьте ⭐ на GitHub!

---

**Made with ❤️ for Russian e-commerce**

</div>
