<?php
resetScenario();
$parent = new WC_Product(10, 10, 'variable'); $variation = new WC_Product(11, 0, 'variation', 10);
$post = (object) array('ID' => 11, 'post_type' => 'product_variation', 'post_parent' => 10); $GLOBALS['posts'][11] = $post;
$item = new WC_Order_Item_Product($variation, 2); $order = new WC_Order(100, array(1 => $item));
wc_reduce_stock_levels($order); wc_increase_stock_levels($order); wc_reduce_stock_levels($order);
wc_restock_refunded_items($order, array(1 => array('qty' => 1)));
check(count($GLOBALS['wpdb']->rows) === 4 && array_values(array_unique(array_column($GLOBALS['wpdb']->rows, 'product_id'))) === array(10), 'order reduction, restoration and refund all use the physical stock owner');
check($GLOBALS['stocks'][10] === 9 && $GLOBALS['wpdb']->rows[4]['qty_after'] === 9.0, 'shared stock history agrees with the parent quantity');
ob_start(); SHFW_Stock_History::render_variation_history(0, array(), $post); $html = ob_get_clean();
check(substr_count($html, 'data-entry-id=') === 4 && shfw_test_contains($html, 'shared stock history'), 'inherited-stock variations show the parent history with an explanation');
$_GET['shfw_variation'] = 11;
ob_start(); SHFW_Stock_History::render_product_data_panel(); $html = ob_get_clean();
check(substr_count($html, 'data-entry-id=') === 4 && shfw_test_contains($html, 'Variation #11'), 'the full variation history resolves its shared stock owner');

resetScenario();
$variation = new WC_Product(11, 10, 'variation'); $order = new WC_Order(100, array(new WC_Order_Item_Product($variation)));
wc_reduce_stock_levels($order); wc_increase_stock_levels($order);
check(array_column($GLOBALS['wpdb']->rows, 'product_id') === array(11, 11), 'independently managed variation stock remains on the variation');
