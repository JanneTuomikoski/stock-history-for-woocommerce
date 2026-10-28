<?php
// Small test doubles for WooCommerce's stock-hook contract. Set SHFW_WC_SOURCE_DIR to run with the official functions instead.
function wc_update_product_stock($product, $quantity = null, $operation = 'set', $updating = false) {
    $owner = wc_get_product($product->get_stock_managed_by_id());
    $prefix = $owner->is_type('variation') ? 'woocommerce_variation' : 'woocommerce_product';
    do_action($prefix . '_before_set_stock', $owner);
    $store = WC_Data_Store::load('product');
    $after = $store->update_product_stock($owner->get_id(), $quantity, $operation);
    $store->read_stock_quantity($owner, $after);
    if (!$updating) $owner->save();
    do_action($prefix . '_set_stock', $owner);
    return $after;
}
function wc_maybe_adjust_line_item_product_stock($item, $quantity = -1) {
    $quantity = $quantity >= 0 ? $quantity : $item->get_quantity();
    $reduced = (int) $item->get_meta('_reduced_stock');
    $refunded = (int) $item->get_meta('_restock_refunded_items');
    $diff = $quantity === 0 ? -$reduced : $quantity - $refunded - $reduced;
    if (!$diff) return false;
    $after = wc_update_product_stock($item->get_product(), abs($diff), $diff > 0 ? 'decrease' : 'increase');
    $item->update_meta_data('_reduced_stock', $quantity - $refunded);
    return ['from' => $after + $diff, 'to' => $after];
}
function wc_reduce_stock_levels($order) {
    foreach ($order->get_items() as $item) {
        if ($item->get_meta('_reduced_stock')) continue;
        $qty = $item->get_quantity();
        $after = wc_update_product_stock($item->get_product(), $qty, 'decrease');
        $item->add_meta_data('_reduced_stock', $qty, true);
        do_action('woocommerce_reduce_order_item_stock', $item, ['product' => $item->get_product(), 'from' => $after + $qty, 'to' => $after], $order);
    }
}
function wc_increase_stock_levels($order) {
    foreach ($order->get_items() as $item) {
        $qty = $item->get_meta('_reduced_stock');
        if (!$qty) continue;
        $after = wc_update_product_stock($item->get_product(), $qty, 'increase');
        $item->delete_meta_data('_reduced_stock');
        do_action('woocommerce_restore_order_item_stock', $item, $after, $after - $qty, $order);
    }
}
function wc_restock_refunded_items($order, $refunded) {
    foreach ($order->get_items() as $id => $item) {
        if (!isset($refunded[$id]) || !$item->get_meta('_reduced_stock')) continue;
        $product = $item->get_product(); $before = $product->get_stock_quantity();
        $after = wc_update_product_stock($product, $refunded[$id]['qty'], 'increase');
        $item->update_meta_data('_reduced_stock', $item->get_meta('_reduced_stock') - $refunded[$id]['qty']);
        $item->update_meta_data('_restock_refunded_items', (int) $item->get_meta('_restock_refunded_items') + $refunded[$id]['qty']);
        do_action('woocommerce_restock_refunded_item', $product->get_id(), $before, $after, $order, $product);
    }
}
