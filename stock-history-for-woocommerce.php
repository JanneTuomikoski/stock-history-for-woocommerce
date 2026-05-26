<?php
/**
 * Plugin Name:       Stock History for WooCommerce
 * Plugin URI:        https://github.com/JanneTuomikoski/stock-history-for-woocommerce
 * Description:       Audit log of every WooCommerce stock change - manual edits, order reductions, and order restorations. Adds a Stock History tab to the product edit screen with inline notes.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Janne Tuomikoski
 * Author URI:        https://github.com/JanneTuomikoski
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stock-history-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 *
 * @package SHFW
 */

if (!defined('ABSPATH')) {
	exit;
}

define('SHFW_VERSION', '1.0.0');
define('SHFW_PLUGIN_FILE', __FILE__);
define('SHFW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHFW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once SHFW_PLUGIN_DIR . 'includes/class-shfw-stock-history.php';

// Declare HPOS (custom order tables) compatibility - we read orders via wc_get_order()
// and the WC_Order API, no direct postmeta access.
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('plugins_loaded', 'shfw_bootstrap');

function shfw_bootstrap() {
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'shfw_missing_wc_notice');
		return;
	}

	load_plugin_textdomain(
		'stock-history-for-woocommerce',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);

	SHFW_Stock_History::init();
}

function shfw_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'Stock History for WooCommerce requires WooCommerce to be installed and activated.',
		'stock-history-for-woocommerce'
	);
	echo '</p></div>';
}

register_activation_hook(__FILE__, array('SHFW_Stock_History', 'install_table'));
