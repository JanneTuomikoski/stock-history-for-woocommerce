<?php
// Run with WP-CLI, --skip-plugins --skip-themes and --exec='define("DISABLE_WP_CRON", true);'.
// Only randomly named connection-scoped temporary tables and an isolated in-memory object cache are used.
if (!defined('WP_CLI') || !WP_CLI || getenv('SHFW_TEST_TEMP_TABLES') !== '1'
    || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || wp_using_ext_object_cache()
    || preg_match('/^(localhost|127\.0\.0\.1)(:[0-9]+)?$/D', DB_HOST) !== 1
    || class_exists('SHFW_Stock_History', false)) {
    throw new RuntimeException('Use a local database, SHFW_TEST_TEMP_TABLES=1, disabled cron and no active plugins or external object cache.');
}
require dirname(__DIR__) . '/includes/class-shfw-stock-history.php';
global $wpdb, $wp_object_cache;
$originalDatabase = $wpdb; $originalCache = $wp_object_cache;
$originalOptions = $wpdb->options;
$originalDatabase->flush(); $wpdb = clone $wpdb;
$wpdb->set_prefix($wpdb->prefix . 'shfwcheck_' . bin2hex(random_bytes(5)) . '_');
$wp_object_cache = new WP_Object_Cache();
$table = $wpdb->prefix . 'shfw_history'; $options = $wpdb->options;
$wpdb->suppress_errors(true);
$scheduled = 0;
$preventCron = static function ($pre, $event) use (&$scheduled) {
    if ($event->hook === SHFW_History_Storage::RETRY_HOOK) { ++$scheduled; return true; }
    return $pre;
};
add_filter('pre_schedule_event', $preventCron, 10, 2);
$checks = 0;
$check = static function ($condition, $label) use (&$checks) {
    if (!$condition) throw new RuntimeException($label);
    ++$checks; WP_CLI::log('ok ' . $label);
};
$failInsert = static function ($sql) use ($table) {
    return strpos($sql, "INSERT INTO `$table`") === 0 ? "SELECT * FROM {$table}_missing" : $sql;
};
$failAck = static function ($sql) use ($options, $table) {
    return strpos($sql, "DELETE FROM `$options`") === 0 && strpos($sql, 'shfw_pending_') !== false ? "SELECT * FROM {$table}_missing" : $sql;
};
try {
    $check($wpdb->query("CREATE TEMPORARY TABLE $options LIKE $originalOptions") !== false, 'create an isolated options table');
    $check($wpdb->query("CREATE TEMPORARY TABLE $table (
        id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, product_id bigint unsigned NOT NULL,
        qty_before double DEFAULT NULL, qty_after double NOT NULL, qty_delta double NOT NULL,
        source varchar(32) NOT NULL, source_ref bigint unsigned DEFAULT NULL, user_id bigint unsigned DEFAULT NULL,
        note text DEFAULT NULL, created_at datetime NOT NULL, event_key char(36) DEFAULT NULL,
        UNIQUE KEY event_key (event_key)
    ) ENGINE=InnoDB") !== false, 'create an isolated history table');
    $check(SHFW_History_Storage::schema_ready(), 'the complete physical schema is accepted');
    $wpdb->query("ALTER TABLE $table DROP INDEX event_key");
    $check(!SHFW_History_Storage::schema_ready(), 'schema verification rejects a missing idempotency constraint');
    $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY event_key (event_key)");

    $productId = random_int(2000000000, 2147480000);
    wp_cache_set($productId, new WP_Post((object) array('ID' => $productId, 'post_type' => 'product', 'post_status' => 'publish', 'filter' => 'raw')), 'posts');
    $row = array('product_id' => $productId, 'qty_before' => 10.0, 'qty_after' => 9.0, 'qty_delta' => -1.0,
        'source' => 'manual', 'source_ref' => null, 'user_id' => null, 'note' => null, 'created_at' => current_time('mysql', true));
    add_filter('query', $failInsert);
    $check(SHFW_History_Storage::save($row) === false, 'an injected history write failure is detected');
    remove_filter('query', $failInsert);
    $pendingLike = $wpdb->esc_like('shfw_pending_') . '%';
    $pending = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM $options WHERE option_name LIKE %s", $pendingLike));
    $check(count($pending) === 1 && $scheduled > 0, 'the original event is queued and a retry is scheduled');
    $check((bool) get_option('shfw_logging_health'), 'the database failure produces persistent logging health state');
    // The table above is already complete: skip dbDelta, which must not create non-temporary tables in this test.
    update_option('shfw_db_version', SHFW_Stock_History::DB_VERSION, false);
    add_filter('query', $failAck);
    SHFW_History_Storage::retry_pending();
    remove_filter('query', $failAck);
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") === 1 && get_option($pending[0]), 'a failed acknowledgement preserves the saved event and pending recovery record');
    SHFW_History_Storage::retry_pending(); SHFW_History_Storage::retry_pending();
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") === 1 && !get_option($pending[0]), 'retrying an already-saved event creates no duplicate');
    $saved = $wpdb->get_row("SELECT * FROM $table LIMIT 1", ARRAY_A);
    $check((float) $saved['qty_before'] === 10.0 && (float) $saved['qty_after'] === 9.0, 'recovery preserves the original quantities');
    $check($saved['source_ref'] === null && $saved['user_id'] === null && $saved['note'] === null, 'nullable columns remain null through real wpdb formats');
    $check(!get_option('shfw_logging_health'), 'successful recovery clears the recovered health warning');
    WP_CLI::success("$checks temporary-table storage checks passed.");
} finally {
    remove_filter('query', $failInsert); remove_filter('query', $failAck);
    remove_filter('pre_schedule_event', $preventCron, 10);
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $table");
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS $options");
    $wpdb = $originalDatabase; $wp_object_cache = $originalCache;
}
