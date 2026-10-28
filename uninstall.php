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
delete_option('shfw_logging_health');
wp_clear_scheduled_hook('shfw_retry_history');
$pending = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('shfw_pending_') . '%'));
foreach ($pending as $option_name) {
	delete_option($option_name);
}
