<?php
resetScenario();
$GLOBALS['wpdb']->failSchema = true;
SHFW_Stock_History::install_table();
check(!get_option('shfw_db_version') && get_option('shfw_logging_health'), 'failed installation does not mark the schema current and records its health error');
$GLOBALS['wpdb']->failSchema = false; SHFW_Stock_History::maybe_upgrade_table();
check($GLOBALS['dbDeltaCalls'] === 2 && get_option('shfw_db_version') === SHFW_Stock_History::DB_VERSION, 'the next request retries and verifies the schema');

$GLOBALS['wpdb']->failInsert = true;
$product = new WC_Product(10, 10); $product->set_stock_quantity(9); $product->save();
$queued = array_filter(array_keys($GLOBALS['options']), static fn ($key) => strpos($key, 'shfw_pending_') === 0);
check($GLOBALS['stocks'][10] === 9 && count($queued) === 1 && !$GLOBALS['wpdb']->rows, 'a failed audit write preserves the stock update and queues the original event');
check(wp_next_scheduled('shfw_retry_history') && get_option('shfw_logging_health'), 'failed writes schedule a background retry and retain a health warning');
SHFW_History_Storage::retry_pending();
check(!$GLOBALS['wpdb']->rows && get_option(reset($queued)), 'a failed recovery attempt keeps its event pending');
$GLOBALS['wpdb']->failInsert = false;
$GLOBALS['failAcknowledgement'] = true;
SHFW_History_Storage::retry_pending();
check(count($GLOBALS['wpdb']->rows) === 1 && get_option(reset($queued)), 'a lost recovery acknowledgement leaves the saved event queued');
$GLOBALS['wpdb']->rows[1]['note'] = 'Keep this annotation';
$GLOBALS['failAcknowledgement'] = false;
SHFW_History_Storage::retry_pending(); SHFW_History_Storage::retry_pending();
check(count($GLOBALS['wpdb']->rows) === 1 && !get_option(reset($queued)) && !get_option('shfw_logging_health'), 'recovery acknowledges an already-saved event without duplicates and clears recovered health');
check((float) $GLOBALS['wpdb']->rows[1]['qty_before'] === 10.0 && (float) $GLOBALS['wpdb']->rows[1]['qty_after'] === 9.0, 'recovered entries retain their original quantities');
check($GLOBALS['wpdb']->rows[1]['note'] === 'Keep this annotation', 'an idempotent recovery retry does not overwrite an edited note');

$GLOBALS['wpdb']->failInsert = true; $product->set_stock_quantity(8); $product->save();
unset($GLOBALS['posts'][10]); $GLOBALS['wpdb']->failInsert = false;
SHFW_History_Storage::retry_pending();
check(count($GLOBALS['wpdb']->rows) === 1, 'recovery never recreates history for a deleted product');
$GLOBALS['wpdb']->failRead = true;
SHFW_History_Storage::report_failure('write'); SHFW_History_Storage::clear_health_if_recovered();
check((bool) get_option('shfw_logging_health'), 'a queue read failure cannot clear the logging health warning');
$GLOBALS['wpdb']->failRead = false;
$GLOBALS['permissions']['manage_woocommerce'] = true;
ob_start(); SHFW_History_Storage::health_notice(); $html = ob_get_clean();
check(shfw_test_contains($html, 'notice-error'), 'administrators receive the persistent logging health warning');
$GLOBALS['permissions']['manage_woocommerce'] = false;
ob_start(); SHFW_History_Storage::health_notice(); $html = ob_get_clean();
check($html === '', 'logging health details are not displayed to unprivileged users');

resetScenario(); $GLOBALS['wpdb']->failInsert = true; $GLOBALS['failOptionWrites'] = true;
$product = new WC_Product(10, 10); $product->set_stock_quantity(9); $product->save();
check(get_option('shfw_logging_health')['kind'] === 'unrecoverable', 'failure to persist the recovery event is recorded as unrecoverable');
$GLOBALS['wpdb']->failInsert = false; SHFW_History_Storage::clear_health_if_recovered();
check(get_option('shfw_logging_health')['kind'] === 'unrecoverable', 'a later healthy write cannot silently clear evidence of a lost event');
