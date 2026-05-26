<?php
/**
 * Stock History for WooCommerce - uninstall handler.
 *
 * Drops the custom history table and removes plugin options.
 * Runs only when the plugin is deleted via the WP Plugins screen.
 *
 * @package SHFW
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$table = $wpdb->prefix . 'shfw_history';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

delete_option('shfw_db_version');
