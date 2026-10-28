<?php
resetScenario();
$product = new WC_Product(10, 10); $product->set_stock_quantity(9); $product->save();
foreach (array(str_repeat('ä', 249) . '€', str_repeat('🙂', 500), str_repeat('x', 501)) as $note) {
    $result = noteRequest($note);
    preg_match_all('/./us', $result->payload['note'] ?? '', $characters);
    check($result->success && preg_match('//u', $result->payload['note']) === 1 && count($characters[0]) <= 500, 'notes retain valid UTF-8 under the 500-character limit');
}
check(strlen(noteRequest(str_repeat('🙂', 501))->payload['note']) === 2000, 'emoji notes are capped at 500 complete Unicode code points');
$GLOBALS['nonceValid'] = false; check(noteRequest('denied')->status === 403, 'a note update requires a valid nonce');
$GLOBALS['nonceValid'] = true; $GLOBALS['permissions']['edit_products'] = false;
check(noteRequest('denied')->status === 403, 'a note update requires product editing rights');
$GLOBALS['permissions']['edit_products'] = true; $GLOBALS['permissions']['edit_post:10'] = false;
check(noteRequest('denied')->status === 403, 'product-specific permissions are enforced');
$GLOBALS['permissions']['edit_post:10'] = true;
$GLOBALS['wpdb']->rows[1]['note'] = '<img src=x onerror="alert(1)">';
$render = new ReflectionMethod('SHFW_Stock_History', 'render_history_row'); $render->setAccessible(true);
ob_start(); $render->invoke(null, (object) $GLOBALS['wpdb']->rows[1]); $html = ob_get_clean();
check(!shfw_test_contains($html, '<img ') && shfw_test_contains($html, '&lt;img '), 'stored note markup remains escaped');
