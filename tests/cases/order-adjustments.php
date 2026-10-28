<?php
resetScenario();
$product = new WC_Product(10, 10); $product->set_stock_quantity(12); $product->save();
check(count($GLOBALS['wpdb']->rows) === 1 && $GLOBALS['wpdb']->rows[1]['qty_delta'] === 2.0, 'manual stock save records the real delta');

foreach (array(3, 1, 0) as $quantity) {
    resetScenario();
    $product = new WC_Product(10, 8); $item = new WC_Order_Item_Product($product, $quantity, 123);
    $item->meta['_reduced_stock'] = 2;
    wc_maybe_adjust_line_item_product_stock($item, $quantity);
    $row = $GLOBALS['wpdb']->rows[1] ?? null;
    check($row && $row['source'] === 'order_adjust' && $row['source_ref'] === 123, 'order edit retains its order context for quantity ' . $quantity);
    check($row['qty_before'] === 8.0 && $row['qty_after'] === (float) $GLOBALS['stocks'][10], 'order edit records the actual before and after quantities');
}
resetScenario();
$product = new WC_Product(10, 8); $item = new WC_Order_Item_Product($product, 3); $item->meta = array('_reduced_stock' => 2, '_restock_refunded_items' => 1);
wc_maybe_adjust_line_item_product_stock($item);
check(count($GLOBALS['wpdb']->rows) === 0, 'an unchanged partially refunded order creates no stock event');
$item->quantity = 4; wc_maybe_adjust_line_item_product_stock($item);
check(count($GLOBALS['wpdb']->rows) === 1 && $GLOBALS['wpdb']->rows[1]['qty_delta'] === -1.0, 'partial refunds are accounted for when adjusting order stock');
