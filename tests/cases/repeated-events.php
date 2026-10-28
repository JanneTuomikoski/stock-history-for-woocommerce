<?php
resetScenario();
$product = new WC_Product(10, 10); $item = new WC_Order_Item_Product($product); $order = new WC_Order(100, array(1 => $item));
wc_reduce_stock_levels($order); wc_increase_stock_levels($order); wc_reduce_stock_levels($order);
check(count($GLOBALS['wpdb']->rows) === 3, 'a rapid reduce / restore / reduce cycle records all three mutations');
check(array_column($GLOBALS['wpdb']->rows, 'qty_after') === array(9.0, 10.0, 9.0), 'the final history row matches real stock after a repeated cycle');
wc_reduce_stock_levels($order);
check(count($GLOBALS['wpdb']->rows) === 3 && $GLOBALS['stocks'][10] === 9, 'WooCommerce repeated reduction without a mutation creates no extra history');

resetScenario();
$product = new WC_Product(10, 10); $item = new WC_Order_Item_Product($product, 2); $order = new WC_Order(100, array(1 => $item));
wc_reduce_stock_levels($order); wc_restock_refunded_items($order, array(1 => array('qty' => 1)));
wc_reduce_stock_levels(new WC_Order(200, array(new WC_Order_Item_Product($product))));
wc_restock_refunded_items($order, array(1 => array('qty' => 1)));
check(count($GLOBALS['wpdb']->rows) === 4 && $GLOBALS['wpdb']->rows[4]['source'] === 'refund', 'a second partial refund returning to the same quantity is retained');
