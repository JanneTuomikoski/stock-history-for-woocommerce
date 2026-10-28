<?php
resetScenario();
$order = new WC_Order(100, array());
$GLOBALS['permissions']['edit_shop_order:100'] = true;
$format = new ReflectionMethod('SHFW_Stock_History', 'format_source'); $format->setAccessible(true);
foreach (array(false, true) as $hpos) {
    $GLOBALS['hpos'] = $hpos;
    foreach (array('order_reduce', 'order_restore', 'order_adjust', 'refund') as $source) {
        $html = $format->invoke(null, (object) array('source' => $source, 'source_ref' => 100));
        check(shfw_test_contains($html, '<a ') && shfw_test_contains($html, $hpos ? 'page=wc-orders' : 'post.php?post=100'), 'order source links use the active storage editor: ' . $source . ($hpos ? ' HPOS' : ' legacy'));
    }
}
$GLOBALS['permissions']['edit_shop_order:100'] = false;
$row = (object) array('source' => 'order_reduce', 'source_ref' => 100);
check(!shfw_test_contains($format->invoke(null, $row), '<a '), 'users unable to edit an order get a plain order label');
unset($GLOBALS['orders'][100]);
check(shfw_test_contains($format->invoke(null, $row), 'Order #100'), 'deleted orders retain their audit label');
