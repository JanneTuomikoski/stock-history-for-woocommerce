<?php
/** Checked history persistence and recovery, independent of WooCommerce checkout success. */
if (!defined('ABSPATH')) { exit; }

class SHFW_History_Storage {
	const HEALTH_OPTION = 'shfw_logging_health';
	const PENDING_PREFIX = 'shfw_pending_';
	const RETRY_HOOK = 'shfw_retry_history';

	public static function register() {
		add_action('init', array(__CLASS__, 'maybe_schedule_retry'));
		add_action(self::RETRY_HOOK, array(__CLASS__, 'retry_pending'));
		add_action('admin_notices', array(__CLASS__, 'health_notice'));
	}

	public static function schema_ready() {
		global $wpdb;
		$table = $wpdb->prefix . SHFW_Stock_History::TABLE_SUFFIX;
		$columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
		$required = array('id', 'product_id', 'qty_before', 'qty_after', 'qty_delta', 'source', 'source_ref', 'user_id', 'note', 'created_at', 'event_key');
		$index = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = 'event_key' AND Non_unique = 0", ARRAY_A);
		return is_array($columns) && !array_diff($required, $columns) && is_array($index)
			&& count($index) === 1 && $index[0]['Column_name'] === 'event_key';
	}

	public static function save(array $row) {
		$row['event_key'] = wp_generate_uuid4();
		if (self::persist($row)) {
			self::clear_health_if_recovered();
			return true;
		}
		// Separate options remain available when the history table is missing or its writes fail.
		// Each event has its own key, so concurrent failed writes cannot overwrite one another.
		$queued = add_option(self::PENDING_PREFIX . $row['event_key'], wp_json_encode($row), '', false);
		self::report_failure($queued ? 'write' : 'unrecoverable');
		self::maybe_schedule_retry();
		return false;
	}

	private static function persist(array $row) {
		global $wpdb;
		$table = $wpdb->prefix . SHFW_Stock_History::TABLE_SUFFIX;
		$previous = $wpdb->suppress_errors(true);
		try {
			$inserted = $wpdb->insert($table, $row, array('%d', '%f', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s'));
			// A previous worker may have saved this event before dying or losing the acknowledgement.
			return $inserted === 1 || (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key = %s", $row['event_key']));
		} catch (Throwable $error) {
			return false;
		} finally {
			$wpdb->suppress_errors($previous);
		}
	}

	public static function report_failure($kind) {
		$existing = get_option(self::HEALTH_OPTION);
		$existing = is_array($existing) && isset($existing['kind']) ? $existing : null;
		if (in_array($kind, array('schema', 'write', 'unrecoverable'), true)) { delete_option(SHFW_Stock_History::DB_VERSION_OPTION); }
		if ($existing && $existing['kind'] === 'unrecoverable') { return; }
		update_option(self::HEALTH_OPTION, array('kind' => $kind, 'at' => current_time('mysql', true)), false);
		if (!$existing || $existing['kind'] !== $kind) {
			error_log('[SHFW] History logging needs attention (' . $kind . '). Check database permissions and storage.');
		}
	}

	public static function clear_health_if_recovered() {
		$health = get_option(self::HEALTH_OPTION);
		if (is_array($health) && isset($health['kind']) && $health['kind'] !== 'unrecoverable' && self::pending_rows(1) === array()) {
			delete_option(self::HEALTH_OPTION);
		}
	}

	private static function pending_rows($limit) {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT %d",
			$wpdb->esc_like(self::PENDING_PREFIX) . '%', $limit
		));
		return $wpdb->last_error === '' && is_array($rows) ? $rows : null;
	}

	public static function maybe_schedule_retry() {
		$pending = self::pending_rows(1);
		if ($pending === null) { self::report_failure('recovery'); }
		if ($pending !== array() && !wp_next_scheduled(self::RETRY_HOOK)) {
			wp_schedule_single_event(time() + 60, self::RETRY_HOOK);
		}
	}

	public static function retry_pending() {
		SHFW_Stock_History::maybe_upgrade_table();
		$pending_rows = self::pending_rows(100);
		if ($pending_rows === null) { self::report_failure('recovery'); self::maybe_schedule_retry(); return; }
		foreach ($pending_rows as $pending) {
			$row = json_decode($pending->option_value, true);
			if (!is_array($row) || empty($row['event_key']) || empty($row['product_id'])
				|| array_keys($row) !== array('product_id', 'qty_before', 'qty_after', 'qty_delta', 'source', 'source_ref', 'user_id', 'note', 'created_at', 'event_key')
				|| $pending->option_name !== self::PENDING_PREFIX . $row['event_key']) {
				self::report_failure('unrecoverable');
				continue;
			}
			// Product deletion deliberately removes its history. Do not resurrect queued orphan entries.
			if (!in_array(get_post_type($row['product_id']), array('product', 'product_variation'), true) || self::persist($row)) {
				delete_option($pending->option_name);
			} else {
				self::report_failure('write');
			}
		}
		self::clear_health_if_recovered();
		self::maybe_schedule_retry();
	}

	public static function health_notice() {
		if (!get_option(self::HEALTH_OPTION) || (!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))) { return; }
		echo '<div class="notice notice-error"><p>';
		esc_html_e('Stock History logging needs attention. Some changes may be missing. Pending entries will retry automatically; check database permissions and error logs.', 'stock-history-for-woocommerce');
		echo '</p></div>';
	}
}
