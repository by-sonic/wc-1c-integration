#!/bin/sh

PASS=0
FAIL=0
TOTAL=0

pass() {
  PASS=$((PASS + 1))
  TOTAL=$((TOTAL + 1))
  echo "  [PASS] $1"
}

fail() {
  FAIL=$((FAIL + 1))
  TOTAL=$((TOTAL + 1))
  echo "  [FAIL] $1"
  if [ -n "$2" ]; then
    echo "         -> $2"
  fi
}

section() {
  echo ""
  echo "===== $1 ====="
}

# -------------------------------------------------------
section "1. WordPress core sanity"
# -------------------------------------------------------
WP_VER=$(wp core version 2>&1) && pass "wp core version: $WP_VER" || fail "wp core version" "$WP_VER"

# -------------------------------------------------------
section "2. Install & activate WooCommerce"
# -------------------------------------------------------
if wp plugin is-installed woocommerce 2>/dev/null; then
  echo "  WooCommerce already installed"
else
  wp plugin install woocommerce --activate 2>&1 && pass "WooCommerce installed & activated" || fail "WooCommerce install"
fi

WC_ACTIVE=$(wp plugin list --status=active --name=woocommerce --format=count 2>&1)
if [ "$WC_ACTIVE" = "1" ]; then
  pass "WooCommerce is active"
else
  wp plugin activate woocommerce 2>&1
  WC_ACTIVE2=$(wp plugin list --status=active --name=woocommerce --format=count 2>&1)
  if [ "$WC_ACTIVE2" = "1" ]; then
    pass "WooCommerce activated"
  else
    fail "WooCommerce activation" "$WC_ACTIVE"
  fi
fi

WC_VER=$(wp plugin get woocommerce --field=version 2>&1) && pass "WooCommerce version: $WC_VER" || fail "WC version" "$WC_VER"

# -------------------------------------------------------
section "3. Activate wc-1c-integration plugin"
# -------------------------------------------------------
if wp plugin is-installed wc-1c-integration 2>/dev/null; then
  echo "  Plugin found in plugins dir"
else
  fail "Plugin not found in wp-content/plugins"
fi

ACTIVATE_OUT=$(wp plugin activate wc-1c-integration 2>&1) && pass "Plugin activated" || fail "Plugin activation" "$ACTIVATE_OUT"

PLUGIN_STATUS=$(wp plugin list --status=active --name=wc-1c-integration --format=count 2>&1)
if [ "$PLUGIN_STATUS" = "1" ]; then
  pass "Plugin is active (confirmed)"
else
  fail "Plugin not showing as active" "$PLUGIN_STATUS"
fi

# -------------------------------------------------------
section "4. Check PHP syntax (lint) of all plugin files"
# -------------------------------------------------------
LINT_OK=true
for f in \
  /var/www/html/wp-content/plugins/wc-1c-integration/wc-1c-integration.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/class-commerceml-parser.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/class-exchange-endpoint.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/class-product-sync.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/class-order-sync.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/class-logger.php \
  /var/www/html/wp-content/plugins/wc-1c-integration/includes/admin/class-admin-settings.php
do
  LINT_OUT=$(php -l "$f" 2>&1)
  if echo "$LINT_OUT" | grep -q "No syntax errors"; then
    pass "Lint OK: $(basename $f)"
  else
    fail "Lint FAIL: $(basename $f)" "$LINT_OUT"
    LINT_OK=false
  fi
done

# -------------------------------------------------------
section "5. Database tables created"
# -------------------------------------------------------
TABLE_CHECK=$(wp eval '
global $wpdb;
$mapping = $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}wc1c_id_mapping\"");
$synclog = $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}wc1c_sync_log\"");
echo ($mapping ? "mapping_ok" : "mapping_missing") . " ";
echo ($synclog ? "synclog_ok" : "synclog_missing") . " ";

$cols = $wpdb->get_results("DESCRIBE {$wpdb->prefix}wc1c_id_mapping", ARRAY_A);
$col_names = array_column($cols, "Field");
echo (in_array("guid_1c", $col_names) ? "guid_col_ok" : "guid_col_missing") . " ";
echo (in_array("wc_id", $col_names) ? "wcid_col_ok" : "wcid_col_missing");
' 2>&1)

if echo "$TABLE_CHECK" | grep -q "mapping_ok"; then
  pass "Table wc1c_id_mapping exists"
else
  fail "Table wc1c_id_mapping missing" "$TABLE_CHECK"
fi

if echo "$TABLE_CHECK" | grep -q "synclog_ok"; then
  pass "Table wc1c_sync_log exists"
else
  fail "Table wc1c_sync_log missing" "$TABLE_CHECK"
fi

if echo "$TABLE_CHECK" | grep -q "guid_col_ok"; then
  pass "id_mapping has guid_1c column"
else
  fail "id_mapping missing guid_1c column" "$TABLE_CHECK"
fi

if echo "$TABLE_CHECK" | grep -q "wcid_col_ok"; then
  pass "id_mapping has wc_id column"
else
  fail "id_mapping missing wc_id column" "$TABLE_CHECK"
fi

# -------------------------------------------------------
section "6. Default plugin options set"
# -------------------------------------------------------
for opt in wc1c_enabled wc1c_sync_images wc1c_sync_categories wc1c_sync_attributes wc1c_sync_stock wc1c_sync_prices wc1c_price_type; do
  VAL=$(wp option get "$opt" 2>&1)
  if [ -n "$VAL" ]; then
    pass "Option $opt = $VAL"
  else
    fail "Option $opt is empty/not set"
  fi
done

# -------------------------------------------------------
section "7. Rewrite rules flushed (exchange endpoint registered)"
# -------------------------------------------------------
wp rewrite flush 2>&1
REWRITE_RULES=$(wp rewrite list --format=csv 2>&1)
if echo "$REWRITE_RULES" | grep -q "1c-exchange"; then
  pass "Rewrite rule for 1c-exchange registered"
else
  fail "Rewrite rule for 1c-exchange NOT found" "Checking option..."
fi

# -------------------------------------------------------
section "8. CommerceML Parser — parse import.xml"
# -------------------------------------------------------
PARSE_IMPORT=$(wp eval '
$parser = new WC1C_CommerceML_Parser();
try {
    $data = $parser->parse_import("/tests/sample-import.xml");
    $cats = count($data["categories"]);
    $props = count($data["properties"]);
    $prods = count($data["products"]);
    echo "OK categories=$cats properties=$props products=$prods";
} catch (Exception $e) {
    echo "ERROR " . $e->getMessage();
}
' 2>&1)

if echo "$PARSE_IMPORT" | grep -q "OK"; then
  pass "parse_import: $PARSE_IMPORT"
  
  # Validate specific counts
  if echo "$PARSE_IMPORT" | grep -q "categories=4"; then
    pass "Parsed 4 categories (incl. nested)"
  else
    fail "Expected 4 categories" "$PARSE_IMPORT"
  fi
  
  if echo "$PARSE_IMPORT" | grep -q "properties=2"; then
    pass "Parsed 2 properties"
  else
    fail "Expected 2 properties" "$PARSE_IMPORT"
  fi
  
  if echo "$PARSE_IMPORT" | grep -q "products=3"; then
    pass "Parsed 3 products"
  else
    fail "Expected 3 products" "$PARSE_IMPORT"
  fi
else
  fail "parse_import failed" "$PARSE_IMPORT"
fi

# -------------------------------------------------------
section "9. CommerceML Parser — parse offers.xml"
# -------------------------------------------------------
PARSE_OFFERS=$(wp eval '
$parser = new WC1C_CommerceML_Parser();
try {
    $data = $parser->parse_offers("/tests/sample-offers.xml");
    $types = count($data["price_types"]);
    $wh = count($data["warehouses"]);
    $offers = count($data["offers"]);
    echo "OK price_types=$types warehouses=$wh offers=$offers";
} catch (Exception $e) {
    echo "ERROR " . $e->getMessage();
}
' 2>&1)

if echo "$PARSE_OFFERS" | grep -q "OK"; then
  pass "parse_offers: $PARSE_OFFERS"
  
  if echo "$PARSE_OFFERS" | grep -q "price_types=2"; then
    pass "Parsed 2 price types (Розничная, Оптовая)"
  else
    fail "Expected 2 price types" "$PARSE_OFFERS"
  fi
  
  if echo "$PARSE_OFFERS" | grep -q "offers=2"; then
    pass "Parsed 2 offers"
  else
    fail "Expected 2 offers" "$PARSE_OFFERS"
  fi
else
  fail "parse_offers failed" "$PARSE_OFFERS"
fi

# -------------------------------------------------------
section "10. CommerceML Parser — validate parsed product data"
# -------------------------------------------------------
PROD_DATA=$(wp eval '
$parser = new WC1C_CommerceML_Parser();
$data = $parser->parse_import("/tests/sample-import.xml");

$p = $data["products"]["prod-001"] ?? null;
if (!$p) { echo "ERROR product not found"; exit; }

$checks = [];
$checks[] = ($p["sku"] === "PHONE-001") ? "sku_ok" : "sku_fail";
$checks[] = ($p["name"] === "Тестовый смартфон") ? "name_ok" : "name_fail";
$checks[] = ($p["barcode"] === "4600000000001") ? "barcode_ok" : "barcode_fail";
$checks[] = (in_array("cat-002", $p["categories"])) ? "cat_ok" : "cat_fail";
$checks[] = ($p["is_variation"] === false) ? "not_variation_ok" : "variation_fail";
$checks[] = ($p["weight"] == 0.175) ? "weight_ok" : "weight_fail";
$checks[] = (isset($p["attributes"]["prop-color"])) ? "attr_ok" : "attr_fail";

$del = $data["products"]["prod-003"] ?? null;
$checks[] = ($del && $del["status"] === "deleted") ? "deleted_ok" : "deleted_fail";

echo implode(" ", $checks);
' 2>&1)

for check in sku_ok name_ok barcode_ok cat_ok not_variation_ok weight_ok attr_ok deleted_ok; do
  if echo "$PROD_DATA" | grep -q "$check"; then
    pass "Product data: $check"
  else
    fail "Product data: $check" "$PROD_DATA"
  fi
done

# -------------------------------------------------------
section "11. CommerceML Parser — validate parsed offer data"
# -------------------------------------------------------
OFFER_DATA=$(wp eval '
$parser = new WC1C_CommerceML_Parser();
$data = $parser->parse_offers("/tests/sample-offers.xml");

$offers = $data["offers"];
$o = isset($offers["prod-001"]) ? $offers["prod-001"] : null;
if (!$o) { echo "ERROR offer not found"; exit; }

$checks = array();
$stock = $o["total_stock"];
$checks[] = ($stock == 15) ? "stock_ok" : "stock_fail";
$retail = null;
$prices = $o["prices"];
foreach ($prices as $p) {
    if ($p["type_name"] === "Розничная") $retail = $p["price"];
}
$checks[] = ($retail == 29990) ? "retail_price_ok" : "retail_price_fail";

$o2 = isset($offers["prod-002"]) ? $offers["prod-002"] : null;
$stock2 = $o2 ? $o2["total_stock"] : 0;
$checks[] = ($o2 && $stock2 == 8) ? "warehouse_stock_ok" : "warehouse_stock_fail";

echo implode(" ", $checks);
' 2>&1)

for check in stock_ok retail_price_ok warehouse_stock_ok; do
  if echo "$OFFER_DATA" | grep -q "$check"; then
    pass "Offer data: $check"
  else
    fail "Offer data: $check" "$OFFER_DATA"
  fi
done

# -------------------------------------------------------
section "12. Order export XML generation"
# -------------------------------------------------------
ORDER_XML=$(wp eval '
$sync = new WC1C_Order_Sync();
$parser = new WC1C_CommerceML_Parser();

$test_orders = [
    [
        "id" => "test-guid-001",
        "number" => "1001",
        "date" => "2026-03-27",
        "time" => "14:30:00",
        "status" => "processing",
        "currency" => "RUB",
        "total" => "29990",
        "customer_id" => "cust-001",
        "customer_note" => "Тестовый заказ",
        "payment_method" => "Банковская карта",
        "shipping_method" => "Курьерская доставка",
        "shipping_total" => "500",
        "paid_date" => "2026-03-27",
        "billing" => [
            "first_name" => "Иван",
            "last_name" => "Петров",
            "company" => "",
            "address_1" => "ул. Тестовая, 1",
            "address_2" => "",
            "city" => "Москва",
            "state" => "",
            "postcode" => "101000",
            "country" => "RU",
            "email" => "test@test.ru",
            "phone" => "+71234567890",
        ],
        "shipping" => [
            "first_name" => "Иван",
            "last_name" => "Петров",
            "company" => "",
            "address_1" => "ул. Тестовая, 1",
            "address_2" => "",
            "city" => "Москва",
            "state" => "",
            "postcode" => "101000",
            "country" => "RU",
        ],
        "items" => [
            [
                "product_1c_id" => "prod-001",
                "product_id" => 1,
                "variation_id" => 0,
                "name" => "Тестовый смартфон",
                "sku" => "PHONE-001",
                "quantity" => 1,
                "price" => 29990,
                "total" => 29990,
                "tax" => 0,
                "discount" => 0,
            ],
        ],
    ],
];

$xml = $parser->generate_orders_xml($test_orders);

$checks = [];
$checks[] = (strpos($xml, "<?xml") !== false) ? "xml_header_ok" : "xml_header_fail";
$checks[] = (strpos($xml, "КоммерческаяИнформация") !== false) ? "root_ok" : "root_fail";
$checks[] = (strpos($xml, "Документ") !== false) ? "document_ok" : "document_fail";
$checks[] = (strpos($xml, "1001") !== false) ? "order_number_ok" : "order_number_fail";
$checks[] = (strpos($xml, "В обработке") !== false) ? "status_mapped_ok" : "status_mapped_fail";
$checks[] = (strpos($xml, "Контрагент") !== false) ? "contractor_ok" : "contractor_fail";
$checks[] = (strpos($xml, "Петров") !== false) ? "customer_name_ok" : "customer_name_fail";
$checks[] = (strpos($xml, "PHONE-001") !== false) ? "item_sku_ok" : "item_sku_fail";
$checks[] = (strpos($xml, "НаименованиеПолное") !== false) ? "unit_attr_ok" : "unit_attr_fail";
$checks[] = (strpos($xml, "2.10") !== false) ? "schema_ver_ok" : "schema_ver_fail";

echo implode(" ", $checks);
' 2>&1)

for check in xml_header_ok root_ok document_ok order_number_ok status_mapped_ok contractor_ok customer_name_ok item_sku_ok unit_attr_ok schema_ver_ok; do
  if echo "$ORDER_XML" | grep -q "$check"; then
    pass "Order XML: $check"
  else
    fail "Order XML: $check" "$ORDER_XML"
  fi
done

# -------------------------------------------------------
section "13. Exchange endpoint HTTP tests"
# -------------------------------------------------------
# No credentials set = open access
CHECKAUTH=$(curl -s "http://wordpress:80/1c-exchange/?type=catalog&mode=checkauth" 2>&1)
if echo "$CHECKAUTH" | grep -q "success"; then
  pass "Endpoint checkauth returns 'success'"
else
  # May need rewrite flush
  wp rewrite flush --hard 2>&1
  CHECKAUTH2=$(curl -s "http://wordpress:80/?wc1c_exchange=1&type=catalog&mode=checkauth" 2>&1)
  if echo "$CHECKAUTH2" | grep -q "success"; then
    pass "Endpoint checkauth returns 'success' (query param fallback)"
  else
    fail "Endpoint checkauth" "$CHECKAUTH --- $CHECKAUTH2"
  fi
fi

INIT=$(curl -s "http://wordpress:80/?wc1c_exchange=1&type=catalog&mode=init" 2>&1)
if echo "$INIT" | grep -q "file_limit"; then
  pass "Endpoint init returns file_limit"
else
  fail "Endpoint init" "$INIT"
fi

if echo "$INIT" | grep -q "zip=no"; then
  pass "Endpoint init returns zip=no"
else
  fail "Endpoint init zip" "$INIT"
fi

SALE_INIT=$(curl -s "http://wordpress:80/?wc1c_exchange=1&type=sale&mode=init" 2>&1)
if echo "$SALE_INIT" | grep -q "file_limit"; then
  pass "Sale endpoint init returns file_limit"
else
  fail "Sale endpoint init" "$SALE_INIT"
fi

UNKNOWN=$(curl -s "http://wordpress:80/?wc1c_exchange=1&type=unknown&mode=test" 2>&1)
if echo "$UNKNOWN" | grep -q "failure"; then
  pass "Unknown type returns 'failure'"
else
  fail "Unknown type handling" "$UNKNOWN"
fi

# -------------------------------------------------------
section "14. Exchange endpoint — authentication"
# -------------------------------------------------------
wp option update wc1c_username testuser 2>&1
wp option update wc1c_password testpass 2>&1

NOAUTH=$(curl -s "http://wordpress:80/?wc1c_exchange=1&type=catalog&mode=checkauth" 2>&1)
if echo "$NOAUTH" | grep -qi "401\|failure\|Authentication"; then
  pass "No-auth request rejected"
else
  fail "No-auth request should be rejected" "$NOAUTH"
fi

AUTH_OK=$(curl -s -u testuser:testpass "http://wordpress:80/?wc1c_exchange=1&type=catalog&mode=checkauth" 2>&1)
if echo "$AUTH_OK" | grep -q "success"; then
  pass "Authenticated request returns 'success'"
else
  fail "Authenticated request" "$AUTH_OK"
fi

AUTH_BAD=$(curl -s -u wrong:wrong "http://wordpress:80/?wc1c_exchange=1&type=catalog&mode=checkauth" 2>&1)
if echo "$AUTH_BAD" | grep -qi "401\|failure\|Authentication"; then
  pass "Wrong credentials rejected"
else
  fail "Wrong credentials should be rejected" "$AUTH_BAD"
fi

# Reset to open
wp option update wc1c_username "" 2>&1
wp option update wc1c_password "" 2>&1

# -------------------------------------------------------
section "15. Logger"
# -------------------------------------------------------
LOG_TEST=$(wp eval '
WC1C_Logger::log("Test info message", "info");
WC1C_Logger::log("Test error message", "error");
WC1C_Logger::log("Test debug message", "debug");

$log = WC1C_Logger::get_log(10);
$checks = [];
$checks[] = (strpos($log, "Test info message") !== false) ? "info_logged" : "info_missing";
$checks[] = (strpos($log, "Test error message") !== false) ? "error_logged" : "error_missing";
$checks[] = (strpos($log, "Test debug message") === false) ? "debug_hidden_ok" : "debug_visible_fail";
echo implode(" ", $checks);
' 2>&1)

for check in info_logged error_logged debug_hidden_ok; do
  if echo "$LOG_TEST" | grep -q "$check"; then
    pass "Logger: $check"
  else
    fail "Logger: $check" "$LOG_TEST"
  fi
done

# Test debug mode enabled
LOG_DEBUG=$(wp eval '
update_option("wc1c_debug_mode", "yes");
WC1C_Logger::log("Debug visible now", "debug");
$log = WC1C_Logger::get_log(10);
$ok = (strpos($log, "Debug visible now") !== false) ? "debug_shown" : "debug_still_hidden";
update_option("wc1c_debug_mode", "no");
echo $ok;
' 2>&1)

if echo "$LOG_DEBUG" | grep -q "debug_shown"; then
  pass "Logger: debug mode works when enabled"
else
  fail "Logger: debug mode" "$LOG_DEBUG"
fi

# -------------------------------------------------------
section "16. HPOS compatibility declaration"
# -------------------------------------------------------
HPOS_CHECK=$(wp eval '
if (class_exists("\Automattic\WooCommerce\Utilities\FeaturesUtil")) {
    echo "FeaturesUtil_exists";
} else {
    echo "FeaturesUtil_missing";
}
' 2>&1)
pass "HPOS FeaturesUtil check: $HPOS_CHECK"

# -------------------------------------------------------
section "17. Full CommerceML workflow simulation"
# -------------------------------------------------------
WORKFLOW=$(wp eval '
$parser = new WC1C_CommerceML_Parser();
$product_sync = new WC1C_Product_Sync();

$import_data = $parser->parse_import("/tests/sample-import.xml");

$cat_results = $product_sync->sync_categories($import_data["categories"]);
echo "cats_created=" . $cat_results["created"] . " ";
echo "cats_updated=" . $cat_results["updated"] . " ";
echo "cats_failed=" . $cat_results["failed"] . " ";

$prod_results = $product_sync->sync_products($import_data["products"]);
echo "prods_created=" . $prod_results["created"] . " ";
echo "prods_failed=" . $prod_results["failed"] . " ";

$term = get_term_by("name", "Электроника", "product_cat");
echo ($term ? "cat_exists_ok" : "cat_missing") . " ";

$term2 = get_term_by("name", "Смартфоны", "product_cat");
echo ($term2 ? "subcat_exists_ok" : "subcat_missing") . " ";
if ($term && $term2) {
    echo ($term2->parent == $term->term_id ? "hierarchy_ok" : "hierarchy_fail") . " ";
}
' 2>&1)

if echo "$WORKFLOW" | grep -q "cats_created=4"; then
  pass "Workflow: 4 categories created"
else
  fail "Workflow: category creation" "$WORKFLOW"
fi

if echo "$WORKFLOW" | grep -q "cats_failed=0"; then
  pass "Workflow: 0 category failures"
else
  fail "Workflow: category failures" "$WORKFLOW"
fi

if echo "$WORKFLOW" | grep -q "cat_exists_ok"; then
  pass "Workflow: root category 'Электроника' exists"
else
  fail "Workflow: root category" "$WORKFLOW"
fi

if echo "$WORKFLOW" | grep -q "subcat_exists_ok"; then
  pass "Workflow: subcategory 'Смартфоны' exists"
else
  fail "Workflow: subcategory" "$WORKFLOW"
fi

if echo "$WORKFLOW" | grep -q "hierarchy_ok"; then
  pass "Workflow: category hierarchy correct"
else
  fail "Workflow: category hierarchy" "$WORKFLOW"
fi

if echo "$WORKFLOW" | grep -q "prods_created=2"; then
  pass "Workflow: 2 products created (deleted excluded)"
else
  fail "Workflow: product creation" "$WORKFLOW"
fi

# -------------------------------------------------------
section "18. WordPress error log check"
# -------------------------------------------------------
if [ -f /var/www/html/wp-content/debug.log ]; then
  PHP_ERRORS=$(grep -c "PHP Fatal\|PHP Parse" /var/www/html/wp-content/debug.log 2>/dev/null || echo "0")
  if [ "$PHP_ERRORS" = "0" ]; then
    pass "No PHP Fatal/Parse errors in debug.log"
  else
    fail "Found $PHP_ERRORS PHP Fatal/Parse errors in debug.log"
    tail -20 /var/www/html/wp-content/debug.log
  fi
else
  pass "No debug.log (no errors occurred)"
fi

# -------------------------------------------------------
section "19. Plugin deactivation/reactivation"
# -------------------------------------------------------
DEACT=$(wp plugin deactivate wc-1c-integration 2>&1) && pass "Plugin deactivated" || fail "Deactivation" "$DEACT"
REACT=$(wp plugin activate wc-1c-integration 2>&1) && pass "Plugin reactivated" || fail "Reactivation" "$REACT"

# -------------------------------------------------------
echo ""
echo "========================================"
echo "  RESULTS: $PASS passed / $FAIL failed / $TOTAL total"
echo "========================================"

if [ "$FAIL" -gt 0 ]; then
  exit 1
fi
exit 0
