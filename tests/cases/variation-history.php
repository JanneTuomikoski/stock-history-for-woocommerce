<?php
resetScenario();
$variation = new WC_Product(11, 60, 'variation');
$post = (object) array('ID' => 11, 'post_type' => 'product_variation', 'post_parent' => 10);
$GLOBALS['posts'][11] = $post;
for ($i = 59; $i >= 0; --$i) { $variation->set_stock_quantity($i); $variation->save(); }
ob_start(); do_action('woocommerce_product_after_variable_attributes', 0, array(), $post); $html = ob_get_clean();
check(substr_count($html, 'data-entry-id=') === 5 && shfw_test_contains($html, 'View all stock history'), 'the variation editor has a bounded history preview and full-history link');
$_GET = array('shfw_variation' => 11);
ob_start(); SHFW_Stock_History::render_product_data_panel(); $html = ob_get_clean();
check(substr_count($html, 'data-entry-id=') === 50 && shfw_test_contains($html, 'Variation #11'), 'the main panel can show a selected variation with pagination');
check(shfw_test_contains($html, 'shfw_variation=11') && shfw_test_contains($html, 'shfw_offset=50'), 'pagination keeps the selected variation');
$_GET['shfw_offset'] = 50;
ob_start(); SHFW_Stock_History::render_product_data_panel(); $html = ob_get_clean();
check(substr_count($html, 'data-entry-id=') === 10, 'older variation history is accessible');
$GLOBALS['posts'][11]->post_parent = 99;
ob_start(); SHFW_Stock_History::render_product_data_panel(); $html = ob_get_clean();
check(!shfw_test_contains($html, 'data-entry-id='), 'a forged variation ID cannot read another product history');
$GLOBALS['posts'][11]->post_parent = 10;
$GLOBALS['permissions']['edit_post:10'] = false;
ob_start(); SHFW_Stock_History::render_variation_history(0, array(), $post); $html = ob_get_clean();
check($html === '', 'variation preview requires permission on its parent product');
