<?php
require __DIR__ . '/bootstrap.php';
foreach (glob(__DIR__ . '/cases/*.php') as $test) require $test;
echo 'Passed ' . $checks . ' checks on PHP ' . PHP_VERSION . (getenv('SHFW_WC_SOURCE_DIR') ? ' with official WooCommerce functions.' : ' with isolated WooCommerce doubles.') . PHP_EOL;
