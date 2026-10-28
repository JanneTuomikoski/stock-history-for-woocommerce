<?php
declare(strict_types=1);

// Isolated regression support. No WordPress installation, database, mail or network is used.
namespace Automattic\WooCommerce\Enums {
    class ProductType { const VARIATION = 'variation'; }
}
namespace Automattic\WooCommerce\Internal\Orders {
    class OrderNoteGroup { const PRODUCT_STOCK = 'product_stock'; const ERROR = 'error'; }
}
namespace {
define('ABSPATH', __DIR__ . '/wp-stub/');
function shfw_test_contains($text, $part) { return strpos($text, $part) !== false; }
$GLOBALS['hooks'] = $GLOBALS['stocks'] = $GLOBALS['products'] = $GLOBALS['posts'] = $GLOBALS['options'] = [];
$GLOBALS['permissions'] = ['edit_products' => true, 'edit_post:10' => true];
$GLOBALS['nonceValid'] = true;
$GLOBALS['hpos'] = false;
$GLOBALS['currentProductId'] = 10;
$GLOBALS['dbDeltaCalls'] = 0;

function add_action($name, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$name][$priority][] = [$callback, $accepted]; }
function add_filter($name, $callback, $priority = 10, $accepted = 1) { add_action($name, $callback, $priority, $accepted); }
function do_action($name, ...$args) {
    $priorities = $GLOBALS['hooks'][$name] ?? []; ksort($priorities);
    foreach ($priorities as $callbacks) foreach ($callbacks as [$callback, $accepted]) $callback(...array_slice($args, 0, $accepted));
}
function apply_filters($name, $value, ...$args) { return $value; }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? ($name === 'woocommerce_manage_stock' ? 'yes' : $default); }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['stocks'][$id] ?? ''; }
function get_current_user_id() { return 42; }
function current_time($format, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
function wc_stock_amount($amount) { return (int) $amount; }
function is_wp_error($value) { return false; }
function absint($value) { return abs((int) $value); }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_attr_e($text, $domain = '') { echo esc_attr($text); }
function wp_kses_post($text) { return $text; }
function get_the_ID() { return $GLOBALS['currentProductId']; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_edit_post_link($id) { return $GLOBALS['hpos'] ? null : 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
function current_user_can($capability, $id = null) { return $GLOBALS['permissions'][$capability . ($id === null ? '' : ':' . $id)] ?? false; }
function wp_unslash($text) { return stripslashes($text); }
function sanitize_textarea_field($text) { return trim(strip_tags($text)); }
function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
function wc_date_format() { return 'Y-m-d'; }
function wc_time_format() { return 'H:i:s'; }
function get_date_from_gmt($date) { return $date; }
function mysql2date($format, $date, $translate = false) { return $date; }
function get_user_by($field, $id) { return (object) ['display_name' => 'Synthetic user']; }
class AjaxResult extends \Exception {
    public $success, $payload, $status;
    public function __construct($success, $payload, $status) { $this->success = $success; $this->payload = $payload; $this->status = $status; parent::__construct('AJAX ' . $status); }
}
function wp_send_json_error($payload, $status = 200) { throw new AjaxResult(false, $payload, $status); }
function wp_send_json_success($payload, $status = 200) { throw new AjaxResult(true, $payload, $status); }
function check_ajax_referer($action, $field) { if (!$GLOBALS['nonceValid']) throw new AjaxResult(false, [], 403); }

class WC_Product {
    public bool $dirty = false;
    public $id, $quantity, $type, $stockOwner;
    public function __construct($id, $quantity, $type = 'simple', $stockOwner = null) {
        $this->id = $id; $this->quantity = $quantity; $this->type = $type; $this->stockOwner = $stockOwner;
        $GLOBALS['products'][$id] = $this; $GLOBALS['stocks'][$id] = $quantity;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return 'Synthetic product'; }
    public function get_stock_quantity() { return $GLOBALS['stocks'][$this->get_stock_managed_by_id()]; }
    public function get_stock_managed_by_id() { return $this->stockOwner ?? $this->id; }
    public function is_type($type) { return $this->type === $type; }
    public function managing_stock() { return true; }
    public function get_manage_stock() { return true; }
    public function get_low_stock_amount() { return 2; }
    public function set_stock_quantity($quantity) { $this->quantity = $quantity; $this->dirty = true; }
    public function save() {
        if (!$this->dirty) return;
        $prefix = $this->type === 'variation' ? 'woocommerce_variation' : 'woocommerce_product';
        do_action($prefix . '_before_set_stock', $this);
        $GLOBALS['stocks'][$this->id] = $this->quantity;
        $this->dirty = false;
        do_action($prefix . '_set_stock', $this);
    }
}
class WC_Order_Item_Product {
    public array $meta = [];
    public $product, $quantity, $orderId;
    public function __construct($product, $quantity = 1, $orderId = 100) { $this->product = $product; $this->quantity = $quantity; $this->orderId = $orderId; }
    public function get_type() { return 'line_item'; }
    public function is_type($type) { return $type === 'line_item'; }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_order_id() { return $this->orderId; }
    public function get_variation_id() { return $this->product->type === 'variation' ? $this->product->id : 0; }
    public function get_product_id() { return $this->product->stockOwner ?? $this->product->id; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() {}
}
class WC_Order {
    public $id, $items;
    public function __construct($id, $items) { $this->id = $id; $this->items = $items; }
    public function get_id() { return $this->id; }
    public function get_items() { return $this->items; }
    public function add_order_note(...$args) {}
}
class WC_Data_Store {
    public static function load($type) { return new self(); }
    public function update_product_stock($id, $amount, $operation) {
        $current = $GLOBALS['stocks'][$id];
        return $GLOBALS['stocks'][$id] = $operation === 'increase' ? $current + $amount : ($operation === 'decrease' ? $current - $amount : $amount);
    }
    public function read_stock_quantity($product, $quantity) { $product->quantity = $quantity; $product->dirty = false; }
    public function get_stock_reduced($orderId) { return true; }
    public function set_stock_reduced($orderId, $reduced) {}
}
class AuditDatabase {
    public string $prefix = 'audit_';
    public array $rows = [];
    public string $last_error = '';
    public bool $failInsert = false;
    public ?string $lastNoteAttempt = null;
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function prepare($sql, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[dfs]/', function ($match) use (&$i, $args) {
            $value = $args[$i++];
            return $match[0] === '%s' ? "'" . str_replace("'", "''", $value) . "'" : ($match[0] === '%d' ? (string) (int) $value : number_format((float) $value, 6, '.', ''));
        }, $sql);
    }
    public function insert($table, $row, $formats = null) {
        if ($this->failInsert) { $this->last_error = 'Injected disk full'; return false; }
        $row['id'] = count($this->rows) + 1; $this->rows[$row['id']] = $row; return 1;
    }
    public function get_var($sql) {
        if (shfw_test_contains($sql, 'SELECT product_id')) {
            preg_match('/WHERE id = (\d+)/', $sql, $id); return $this->rows[(int) $id[1]]['product_id'] ?? null;
        }
        preg_match('/product_id = (\d+)/', $sql, $product);
        preg_match("/source = '([^']+)'/", $sql, $source);
        preg_match('/source_ref = (\d+)/', $sql, $reference);
        preg_match('/qty_after = (-?[\d.]+)/', $sql, $quantity);
        foreach ($this->rows as $row) {
            if ($row['product_id'] === (int) $product[1] && $row['source'] === $source[1]
                && $row['source_ref'] === (isset($reference[1]) ? (int) $reference[1] : null)
                && $row['qty_after'] === (float) $quantity[1] && strtotime($row['created_at']) >= time() - 5) return $row['id'];
        }
        return null;
    }
    public function get_results($sql) {
        preg_match('/product_id = (\d+)/', $sql, $product);
        return array_map(static fn ($row) => (object) $row, array_values(array_filter($this->rows, static fn ($row) => $row['product_id'] === (int) $product[1])));
    }
    public function update($table, $data, $where, $formats = null, $whereFormats = null) {
        $this->lastNoteAttempt = $data['note'];
        if ($data['note'] !== null && preg_match('//u', $data['note']) !== 1) return false;
        $this->rows[$where['id']]['note'] = $data['note']; return 1;
    }
}
$wcSource = getenv('SHFW_WC_SOURCE_DIR');
if ($wcSource) {
    foreach (['wc-stock-functions.php', 'wc-order-functions.php', 'wc-admin-functions.php'] as $file) require rtrim($wcSource, '/\\') . '/' . $file;
} else {
    require __DIR__ . '/support/stock-functions.php';
}
require dirname(__DIR__) . '/includes/class-shfw-stock-history.php';
SHFW_Stock_History::init();
$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new \RuntimeException('Unexpected result: ' . $message);
    ++$checks; echo 'ok ' . $message . PHP_EOL;
}
function resetScenario() {
    $GLOBALS['wpdb'] = new AuditDatabase();
    $GLOBALS['stocks'] = $GLOBALS['products'] = [];
    $GLOBALS['nonceValid'] = true;
    $GLOBALS['permissions'] = ['edit_products' => true, 'edit_post:10' => true];
    $GLOBALS['posts'] = [10 => (object) ['post_type' => 'product', 'post_parent' => 0]];
    $GLOBALS['hpos'] = false;
    $cache = new \ReflectionProperty('SHFW_Stock_History', 'before_stock_cache');
    $cache->setAccessible(true); $cache->setValue(null, []);
}
function noteRequest($note, $entryId = 1) {
    $_POST = ['entry_id' => $entryId, 'note' => $note];
    try { SHFW_Stock_History::ajax_update_note(); } catch (AjaxResult $result) { return $result; }
    throw new \RuntimeException('AJAX returned without a response.');
}

}
