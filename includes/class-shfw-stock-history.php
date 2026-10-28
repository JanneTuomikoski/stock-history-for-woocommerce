<?php
/**
 * Stock History for WooCommerce - main class.
 *
 * @package SHFW
 */

if (!defined('ABSPATH')) {
	exit;
}

class SHFW_Stock_History {

	const HANDLE              = 'shfw-admin-stock-history';
	const DB_VERSION          = '1.0.0';
	const DB_VERSION_OPTION   = 'shfw_db_version';
	const TABLE_SUFFIX        = 'shfw_history';
	const NONCE_ACTION        = 'shfw_update_note';
	const NOTE_AJAX_ACTION    = 'shfw_update_note';
	const PAGE_SIZE           = 50;
	const NOTE_MAX_LENGTH     = 500;

	/**
	 * Per-request cache of pre-save stock quantities, keyed by product/variation ID.
	 *
	 * @var array<int,int|null>
	 */
	private static $before_stock_cache = array();

	public static function init() {
		add_action('admin_init', array(__CLASS__, 'maybe_upgrade_table'));

		// Direct stock updates (manual edits, REST API, programmatic).
		add_action('woocommerce_product_before_set_stock',   array(__CLASS__, 'capture_before_stock'),    10, 1);
		add_action('woocommerce_variation_before_set_stock', array(__CLASS__, 'capture_before_stock'),    10, 1);
		add_action('woocommerce_product_set_stock',          array(__CLASS__, 'log_direct_set_stock'),    20, 1);
		add_action('woocommerce_variation_set_stock',        array(__CLASS__, 'log_variation_set_stock'), 20, 1);

		// Order-driven stock changes.
		add_action('woocommerce_reduce_order_item_stock',    array(__CLASS__, 'log_order_reduce_item'),   10, 3);
		add_action('woocommerce_restore_order_item_stock',   array(__CLASS__, 'log_order_restore_item'),  10, 4);

		// Refund-driven stock restocks (admin "Restock items" checkbox on a refund).
		// This fires per line item and is distinct from order-cancellation restores.
		add_action('woocommerce_restock_refunded_item',      array(__CLASS__, 'log_refund_restock'),      10, 5);

		// Clean up history when a product or variation is permanently deleted.
		add_action('before_delete_post',                     array(__CLASS__, 'on_product_deleted'),      10, 1);

		// UI: Product Data tab + panel.
		add_filter('woocommerce_product_data_tabs',          array(__CLASS__, 'render_product_data_tab'));
		add_action('woocommerce_product_data_panels',        array(__CLASS__, 'render_product_data_panel'));
		add_action('woocommerce_product_after_variable_attributes', array(__CLASS__, 'render_variation_history'), 10, 3);

		// Asset enqueue + inline-note AJAX.
		add_action('admin_enqueue_scripts',                  array(__CLASS__, 'enqueue_admin_assets'));
		add_action('wp_ajax_' . self::NOTE_AJAX_ACTION,      array(__CLASS__, 'ajax_update_note'));
	}

	/**
	 * Activation hook entry point.
	 */
	public static function install_table() {
		self::install_table_inner();
	}

	/**
	 * Run dbDelta when DB_VERSION advances (covers updates without re-activation).
	 */
	public static function maybe_upgrade_table() {
		if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
			return;
		}
		self::install_table_inner();
	}

	private static function install_table_inner() {
		global $wpdb;
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// Note: dbDelta is fussy - two spaces between PRIMARY KEY and (id), each column on its own line.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			qty_before DOUBLE NULL DEFAULT NULL,
			qty_after DOUBLE NOT NULL,
			qty_delta DOUBLE NOT NULL,
			source VARCHAR(32) NOT NULL,
			source_ref BIGINT UNSIGNED NULL DEFAULT NULL,
			user_id BIGINT UNSIGNED NULL DEFAULT NULL,
			note TEXT NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY created_at (created_at),
			KEY product_created (product_id, created_at)
		) {$collate};";

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta($sql);
			update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
		} catch (Throwable $e) {
			error_log('[SHFW] table install failed: ' . $e->getMessage());
		}
	}

	/**
	 * Cache the pre-save stock value so the matching *_set_stock hook can compute delta.
	 * Reads from the DB (_stock meta) - NOT $product->get_stock_quantity(), which by this
	 * point already returns the new value the data store is about to write.
	 *
	 * @param WC_Product $product
	 */
	public static function capture_before_stock($product) {
		if (!$product instanceof WC_Product) {
			return;
		}
		$persisted = get_post_meta($product->get_id(), '_stock', true);
		if ($persisted === '' || is_null($persisted) || $persisted === false) {
			self::$before_stock_cache[$product->get_id()] = null;
		} else {
			self::$before_stock_cache[$product->get_id()] = (float) $persisted;
		}
	}

	public static function log_direct_set_stock($product) {
		self::log_set_stock($product, 'manual');
	}

	public static function log_variation_set_stock($variation) {
		self::log_set_stock($variation, 'manual');
	}

	private static function log_set_stock($product, $source) {
		try {
			if (!$product instanceof WC_Product) {
				return;
			}

			$product_id = $product->get_id();

			// Always release the cache slot, even when skipping below.
			$qty_before = array_key_exists($product_id, self::$before_stock_cache)
				? self::$before_stock_cache[$product_id]
				: null;
			unset(self::$before_stock_cache[$product_id]);

			// Order-driven stock changes route through wc_reduce_stock_levels /
			// wc_increase_stock_levels, which call wc_update_product_stock → $product->save(),
			// so these data-store hooks also fire. Skip here - the per-item order hooks
			// (woocommerce_reduce_order_item_stock / _restore_) are responsible for those.
			if (self::is_inside_order_stock_change()) {
				return;
			}

			$qty_after = $product->get_stock_quantity();
			if (is_null($qty_after)) {
				return;
			}
			$qty_after = (float) $qty_after;

			if (!is_null($qty_before) && $qty_before === $qty_after) {
				return;
			}

			$qty_delta = is_null($qty_before) ? $qty_after : ($qty_after - $qty_before);
			$source_ref = null;
			// Order quantity edits do not fire the reduce/restore item actions. Their stock hook is the
			// event to record, with the item still available on the adjustment function's call stack.
			foreach (debug_backtrace(0, 30) as $frame) {
				if (isset($frame['function'], $frame['args'][0])
					&& 'wc_maybe_adjust_line_item_product_stock' === $frame['function']
					&& $frame['args'][0] instanceof WC_Order_Item_Product) {
					$source = 'order_adjust';
					$source_ref = (int) $frame['args'][0]->get_order_id();
					break;
				}
			}

			self::insert_entry(array(
				'product_id' => $product_id,
				'qty_before' => $qty_before,
				'qty_after'  => $qty_after,
				'qty_delta'  => $qty_delta,
				'source'     => $source,
				'source_ref' => $source_ref,
				'user_id'    => get_current_user_id() ?: null,
			));
		} catch (Throwable $e) {
			error_log('[SHFW] log_set_stock failed: ' . $e->getMessage());
		}
	}

	/**
	 * Detect whether the current call originated from an order or refund stock-level
	 * adjustment. Used to suppress duplicate 'manual' entries when WC's order flow
	 * saves products - the per-item hooks (order_reduce / order_restore / refund) are
	 * what actually log those changes.
	 */
	private static function is_inside_order_stock_change() {
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
		foreach ($trace as $frame) {
			if (empty($frame['function'])) {
				continue;
			}
			if ('wc_reduce_stock_levels' === $frame['function']
				|| 'wc_increase_stock_levels' === $frame['function']
				|| 'wc_restock_refunded_items' === $frame['function']) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Log stock reduction for a single order line item.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param array                 $change ['product' => WC_Product, 'from' => int, 'to' => int]
	 * @param WC_Order              $order
	 */
	public static function log_order_reduce_item($item, $change, $order) {
		try {
			if (!is_array($change) || empty($change['product']) || !$change['product'] instanceof WC_Product) {
				return;
			}
			if (!$order instanceof WC_Order) {
				return;
			}

			$product    = $change['product'];
			$product_id = (int) $product->get_stock_managed_by_id();
			$qty_before = isset($change['from']) ? (float) $change['from'] : null;
			$qty_after  = isset($change['to'])   ? (float) $change['to']   : null;

			if (is_null($qty_after)) {
				return;
			}
			$qty_delta = is_null($qty_before) ? $qty_after : ($qty_after - $qty_before);
			if (0.0 === (float) $qty_delta) {
				return;
			}

			$order_id = $order->get_id();

			self::insert_entry(array(
				'product_id' => $product_id,
				'qty_before' => $qty_before,
				'qty_after'  => $qty_after,
				'qty_delta'  => $qty_delta,
				'source'     => 'order_reduce',
				'source_ref' => $order_id,
				'user_id'    => get_current_user_id() ?: null,
			));
		} catch (Throwable $e) {
			error_log('[SHFW] log_order_reduce_item failed: ' . $e->getMessage());
		}
	}

	/**
	 * Log stock restoration for a single order line item (cancelled / refunded orders).
	 *
	 * @param WC_Order_Item_Product $item
	 * @param int                   $new_stock
	 * @param int                   $old_stock
	 * @param WC_Order              $order
	 */
	public static function log_order_restore_item($item, $new_stock, $old_stock, $order) {
		try {
			if (!$item instanceof WC_Order_Item_Product || !$order instanceof WC_Order) {
				return;
			}

			$product = $item->get_product();
			if (!$product instanceof WC_Product) {
				return;
			}
			$product_id = (int) $product->get_stock_managed_by_id();
			if ($product_id <= 0) {
				return;
			}

			$qty_before = (float) $old_stock;
			$qty_after  = (float) $new_stock;
			$qty_delta  = $qty_after - $qty_before;
			if (0.0 === $qty_delta) {
				return;
			}

			$order_id = $order->get_id();

			self::insert_entry(array(
				'product_id' => $product_id,
				'qty_before' => $qty_before,
				'qty_after'  => $qty_after,
				'qty_delta'  => $qty_delta,
				'source'     => 'order_restore',
				'source_ref' => $order_id,
				'user_id'    => get_current_user_id() ?: null,
			));
		} catch (Throwable $e) {
			error_log('[SHFW] log_order_restore_item failed: ' . $e->getMessage());
		}
	}

	/**
	 * Log a refund restock - fires per line item when an admin creates a refund with
	 * "Restock refunded items" ticked. Distinct from order cancellation restores so
	 * the source column can tell the two apart in the audit trail.
	 *
	 * @param int        $product_id Variation ID if the line item was a variation, else product ID.
	 * @param int|float  $old_stock
	 * @param int|float  $new_stock
	 * @param WC_Order   $order
	 * @param WC_Product $product
	 */
	public static function log_refund_restock($product_id, $old_stock, $new_stock, $order, $product) {
		try {
			if (false === $new_stock) {
				return;
			}
			if (!$order instanceof WC_Order) {
				return;
			}

			if (!$product instanceof WC_Product) {
				return;
			}
			$product_id = (int) $product->get_stock_managed_by_id();
			if ($product_id <= 0) {
				return;
			}

			$qty_before = (float) $old_stock;
			$qty_after  = (float) $new_stock;
			$qty_delta  = $qty_after - $qty_before;
			if (0.0 === $qty_delta) {
				return;
			}

			$order_id = $order->get_id();

			self::insert_entry(array(
				'product_id' => $product_id,
				'qty_before' => $qty_before,
				'qty_after'  => $qty_after,
				'qty_delta'  => $qty_delta,
				'source'     => 'refund',
				'source_ref' => $order_id,
				'user_id'    => get_current_user_id() ?: null,
			));
		} catch (Throwable $e) {
			error_log('[SHFW] log_refund_restock failed: ' . $e->getMessage());
		}
	}

	/**
	 * Remove history rows when a product or variation is permanently deleted, so the
	 * table doesn't accumulate orphan rows pointing at non-existent IDs.
	 *
	 * @param int $post_id
	 */
	public static function on_product_deleted($post_id) {
		$post_id = (int) $post_id;
		if ($post_id <= 0) {
			return;
		}
		$post_type = get_post_type($post_id);
		if ('product' !== $post_type && 'product_variation' !== $post_type) {
			return;
		}

		global $wpdb;
		$wpdb->delete(self::table_name(), array('product_id' => $post_id), array('%d'));
	}

	private static function insert_entry(array $row) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'product_id' => (int) $row['product_id'],
				'qty_before' => is_null($row['qty_before']) ? null : (float) $row['qty_before'],
				'qty_after'  => (float) $row['qty_after'],
				'qty_delta'  => (float) $row['qty_delta'],
				'source'     => (string) $row['source'],
				'source_ref' => is_null($row['source_ref']) ? null : (int) $row['source_ref'],
				'user_id'    => is_null($row['user_id'])    ? null : (int) $row['user_id'],
				'note'       => null,
				'created_at' => current_time('mysql', true),
			),
			array(
				'%d',
				is_null($row['qty_before']) ? null : '%f',
				'%f',
				'%f',
				'%s',
				is_null($row['source_ref']) ? null : '%d',
				is_null($row['user_id'])    ? null : '%d',
				'%s',
				'%s',
			)
		);
	}

	public static function render_product_data_tab($tabs) {
		$tabs['shfw_stock_history'] = array(
			'label'    => __('Stock History', 'stock-history-for-woocommerce'),
			'target'   => 'shfw_stock_history_data',
			'class'    => array('show_if_simple', 'show_if_variable', 'show_if_external', 'show_if_grouped'),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function render_product_data_panel() {
		$product_id = (int) get_the_ID();
		$variation_id = isset($_GET['shfw_variation']) ? absint($_GET['shfw_variation']) : 0;
		$variation = $variation_id ? get_post($variation_id) : null;
		if ($variation && 'product_variation' === $variation->post_type
			&& (int) $variation->post_parent === $product_id && current_user_can('edit_post', $product_id)) {
			$variation_product = wc_get_product($variation_id);
			$product_id = $variation_product ? (int) $variation_product->get_stock_managed_by_id() : $variation_id;
		} else {
			$variation_id = 0;
		}
		$offset     = isset($_GET['shfw_offset']) ? max(0, (int) $_GET['shfw_offset']) : 0;
		$page_size  = self::PAGE_SIZE;
		$rows       = self::fetch_history($product_id, $page_size + 1, $offset);
		$has_more   = count($rows) > $page_size;
		if ($has_more) {
			array_pop($rows);
		}
		?>
		<div id="shfw_stock_history_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<h4 style="margin-left: 12px;"><?php esc_html_e('Stock History', 'stock-history-for-woocommerce'); ?></h4>
				<?php if ($variation_id) : ?>
					<p style="margin-left: 12px;">
						<?php /* translators: %d: variation ID */ echo esc_html(sprintf(__('Variation #%d', 'stock-history-for-woocommerce'), $variation_id)); ?>
						<a href="<?php echo esc_url(remove_query_arg(array('shfw_variation', 'shfw_offset'))); ?>#shfw_stock_history_data"><?php esc_html_e('Back to parent stock history', 'stock-history-for-woocommerce'); ?></a>
					</p>
				<?php endif; ?>
				<?php if ($variation_id && $product_id !== $variation_id) self::render_shared_stock_notice(); ?>

				<?php if (empty($rows) && $offset === 0) : ?>
					<p style="margin-left: 12px; color: #666; font-style: italic;">
						<?php esc_html_e('No stock changes have been recorded for this product yet.', 'stock-history-for-woocommerce'); ?>
					</p>
				<?php else : ?>
					<?php self::render_history_table($rows, $product_id); ?>

					<?php if ($has_more || $offset > 0) : ?>
						<p style="margin: 8px 12px;">
							<?php if ($offset > 0) : ?>
								<a href="<?php echo esc_url(remove_query_arg('shfw_offset')); ?>#shfw_stock_history_data" class="button">
									<?php esc_html_e('« Newest', 'stock-history-for-woocommerce'); ?>
								</a>
							<?php endif; ?>
							<?php if ($has_more) : ?>
								<a href="<?php echo esc_url(add_query_arg('shfw_offset', $offset + $page_size)); ?>#shfw_stock_history_data" class="button">
									<?php esc_html_e('Older →', 'stock-history-for-woocommerce'); ?>
								</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** The variation editor is loaded over AJAX; show a bounded preview and link to its paginated history. */
	public static function render_variation_history($loop, $variation_data, $variation) {
		if (!is_object($variation) || empty($variation->ID) || empty($variation->post_parent)
			|| !current_user_can('edit_post', (int) $variation->post_parent)) {
			return;
		}
		$variation_id = (int) $variation->ID;
		$product = wc_get_product($variation_id);
		$product_id = $product ? (int) $product->get_stock_managed_by_id() : $variation_id;
		$rows = self::fetch_history($product_id, 5, 0);
		$url = add_query_arg(array('shfw_variation' => $variation_id, 'shfw_offset' => 0), get_edit_post_link((int) $variation->post_parent, 'raw'));
		?>
		<div class="shfw-variation-history">
			<h4><?php esc_html_e('Stock History', 'stock-history-for-woocommerce'); ?></h4>
			<?php if ($product_id !== $variation_id) self::render_shared_stock_notice(); ?>
			<?php if ($rows) : ?>
				<?php self::render_history_table($rows, $product_id); ?>
			<?php else : ?>
				<p><?php esc_html_e('No stock changes have been recorded for this product yet.', 'stock-history-for-woocommerce'); ?></p>
			<?php endif; ?>
			<a href="<?php echo esc_url($url); ?>#shfw_stock_history_data"><?php esc_html_e('View all stock history', 'stock-history-for-woocommerce'); ?></a>
		</div>
		<?php
	}

	private static function render_shared_stock_notice() {
		echo '<p>' . esc_html__('Stock is managed by the parent product. This is its shared stock history.', 'stock-history-for-woocommerce') . '</p>';
	}

	private static function render_history_table($rows, $product_id) {
		?>
					<table class="widefat striped shfw-stock-history-table" data-product-id="<?php echo esc_attr($product_id); ?>">
						<colgroup>
							<col class="shfw-col-date">
							<col class="shfw-col-change">
							<col class="shfw-col-after">
							<col class="shfw-col-source">
							<col class="shfw-col-user">
							<col class="shfw-col-note">
						</colgroup>
						<thead>
							<tr>
								<th><?php esc_html_e('Date', 'stock-history-for-woocommerce'); ?></th>
								<th><?php esc_html_e('Change', 'stock-history-for-woocommerce'); ?></th>
								<th><?php esc_html_e('New stock', 'stock-history-for-woocommerce'); ?></th>
								<th><?php esc_html_e('Source', 'stock-history-for-woocommerce'); ?></th>
								<th><?php esc_html_e('User', 'stock-history-for-woocommerce'); ?></th>
								<th><?php esc_html_e('Note', 'stock-history-for-woocommerce'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $row) : ?>
								<?php self::render_history_row($row); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
		<?php
	}

	private static function render_history_row($row) {
		$qty_delta   = (float) $row->qty_delta;
		$qty_after   = (float) $row->qty_after;
		$delta_class = $qty_delta > 0 ? 'shfw-delta--up' : ($qty_delta < 0 ? 'shfw-delta--down' : '');
		$delta_text  = ($qty_delta > 0 ? '+' : '') . self::format_qty($qty_delta);
		$source_html = self::format_source($row);
		$user_text   = self::format_user($row->user_id);
		$note_value  = (string) $row->note;
		?>
		<tr>
			<td><?php echo esc_html(mysql2date(wc_date_format() . ' ' . wc_time_format(), get_date_from_gmt($row->created_at), true)); ?></td>
			<td><span class="shfw-delta <?php echo esc_attr($delta_class); ?>"><?php echo esc_html($delta_text); ?></span></td>
			<td><?php echo esc_html(self::format_qty($qty_after)); ?></td>
			<td><?php echo wp_kses_post($source_html); ?></td>
			<td><?php echo esc_html($user_text); ?></td>
			<td class="shfw-stock-history-note" data-entry-id="<?php echo esc_attr($row->id); ?>">
				<span class="shfw-note-text" data-empty-label="<?php esc_attr_e('Click to add a note…', 'stock-history-for-woocommerce'); ?>"><?php echo esc_html($note_value); ?></span>
			</td>
		</tr>
		<?php
	}

	private static function format_source($row) {
		switch ($row->source) {
			case 'manual':
				return esc_html__('Manual', 'stock-history-for-woocommerce');

			case 'order_reduce':
			case 'order_restore':
			case 'order_adjust':
			case 'refund':
				$order_id = (int) $row->source_ref;
				if ($order_id <= 0) {
					return esc_html__('Order', 'stock-history-for-woocommerce');
				}
				$link = get_edit_post_link($order_id);
				if ('refund' === $row->source) {
					/* translators: %d: order ID */
					$label = sprintf(__('Order #%d (refund)', 'stock-history-for-woocommerce'), $order_id);
				} elseif ('order_restore' === $row->source) {
					/* translators: %d: order ID */
					$label = sprintf(__('Order #%d (restored)', 'stock-history-for-woocommerce'), $order_id);
				} elseif ('order_adjust' === $row->source) {
					/* translators: %d: order ID */
					$label = sprintf(__('Order #%d (quantity adjusted)', 'stock-history-for-woocommerce'), $order_id);
				} else {
					/* translators: %d: order ID */
					$label = sprintf(__('Order #%d', 'stock-history-for-woocommerce'), $order_id);
				}
				if ($link) {
					return '<a href="' . esc_url($link) . '">' . esc_html($label) . '</a>';
				}
				return esc_html($label);

			case 'system':
			default:
				return esc_html__('System', 'stock-history-for-woocommerce');
		}
	}

	/**
	 * Format a stock quantity for display: whole numbers as integers, fractional
	 * values up to 2 decimal places. Uses locale-aware number formatting.
	 */
	private static function format_qty($qty) {
		$qty = (float) $qty;
		if ($qty == (int) $qty) {
			return number_format_i18n((int) $qty);
		}
		return number_format_i18n($qty, 2);
	}

	private static function format_user($user_id) {
		$user_id = (int) $user_id;
		if ($user_id <= 0) {
			return '-';
		}
		$user = get_user_by('id', $user_id);
		return $user ? $user->display_name : '#' . $user_id;
	}

	private static function fetch_history($product_id, $limit, $offset) {
		global $wpdb;
		$table = self::table_name();

		$sql = $wpdb->prepare(
			"SELECT id, product_id, qty_before, qty_after, qty_delta, source, source_ref, user_id, note, created_at
			 FROM {$table}
			 WHERE product_id = %d
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d OFFSET %d",
			$product_id,
			$limit,
			$offset
		);

		$rows = $wpdb->get_results($sql);
		return is_array($rows) ? $rows : array();
	}

	public static function enqueue_admin_assets($hook) {
		if (!self::is_product_edit_screen($hook)) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			SHFW_PLUGIN_URL . 'assets/js/admin-stock-history.js',
			array('jquery'),
			SHFW_VERSION,
			true
		);

		wp_localize_script(self::HANDLE, 'shfwStockHistory', array(
			'ajaxurl'   => admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce(self::NONCE_ACTION),
			'maxLength' => self::NOTE_MAX_LENGTH,
			'i18n'      => array(
				'clickToAdd' => __('Click to add a note…', 'stock-history-for-woocommerce'),
				'saveError'  => __('Failed to save note. Please try again.', 'stock-history-for-woocommerce'),
			),
		));

		wp_register_style(self::HANDLE, false, array(), SHFW_VERSION);
		wp_enqueue_style(self::HANDLE);
		wp_add_inline_style(self::HANDLE, self::inline_css());
	}

	private static function inline_css() {
		return '
			#shfw_stock_history_data .shfw-stock-history-table, .shfw-variation-history .shfw-stock-history-table { width: calc(100% - 24px); margin: 8px 12px 12px; table-layout: fixed; }
			.shfw-variation-history { clear: both; overflow-x: auto; padding: 12px; }
			#shfw_stock_history_data .shfw-stock-history-table th,
			#shfw_stock_history_data .shfw-stock-history-table td { padding: 8px 10px; vertical-align: middle; word-wrap: break-word; }
			#shfw_stock_history_data .shfw-stock-history-table th { font-weight: 600; }
			#shfw_stock_history_data .shfw-col-date   { width: 18%; }
			#shfw_stock_history_data .shfw-col-change { width: 9%; }
			#shfw_stock_history_data .shfw-col-after  { width: 9%; }
			#shfw_stock_history_data .shfw-col-source { width: 20%; }
			#shfw_stock_history_data .shfw-col-user   { width: 14%; }
			#shfw_stock_history_data .shfw-col-note   { width: 30%; }
			.shfw-delta { display: inline-block; min-width: 36px; padding: 2px 6px; border-radius: 3px; font-weight: 600; text-align: center; }
			.shfw-delta--up   { color: #1e8e3e; background: #e6f4ea; }
			.shfw-delta--down { color: #c62828; background: #fdecea; }
			.shfw-stock-history-note { cursor: pointer; }
			.shfw-stock-history-note .shfw-note-text { display: inline-block; min-height: 18px; min-width: 80px; }
			.shfw-stock-history-note .shfw-note-text:empty::before { content: attr(data-empty-label); color: #888; font-style: italic; }
			.shfw-stock-history-note input { width: 100%; box-sizing: border-box; padding: 4px 6px; }
			.shfw-stock-history-note.is-saving { opacity: 0.6; }
			.shfw-stock-history-note.is-error  { box-shadow: inset 0 0 0 2px #c62828; }
		';
	}

	public static function ajax_update_note() {
		check_ajax_referer(self::NONCE_ACTION, '_ajax_nonce');

		if (!current_user_can('edit_products')) {
			wp_send_json_error(
				array('message' => __('Insufficient permissions.', 'stock-history-for-woocommerce')),
				403
			);
		}

		$entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
		if ($entry_id <= 0) {
			wp_send_json_error(array('message' => 'invalid_entry_id'), 400);
		}

		// Look up the entry's product and gate on per-post capability - closes IDOR
		// where any user with edit_products could touch any row regardless of product access.
		global $wpdb;
		$entry_product_id = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT product_id FROM " . self::table_name() . " WHERE id = %d",
			$entry_id
		));
		if ($entry_product_id <= 0) {
			wp_send_json_error(array('message' => 'entry_not_found'), 404);
		}

		// Variations: capability lives on the parent product.
		$cap_target = $entry_product_id;
		$post       = get_post($entry_product_id);
		if ($post && 'product_variation' === $post->post_type && $post->post_parent) {
			$cap_target = (int) $post->post_parent;
		}
		if (!current_user_can('edit_post', $cap_target)) {
			wp_send_json_error(
				array('message' => __('Insufficient permissions for this product.', 'stock-history-for-woocommerce')),
				403
			);
		}

		$note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
		if (strlen($note) > self::NOTE_MAX_LENGTH) {
			$note = substr($note, 0, self::NOTE_MAX_LENGTH);
		}

		$updated = $wpdb->update(
			self::table_name(),
			array('note' => $note === '' ? null : $note),
			array('id' => $entry_id),
			array($note === '' ? null : '%s'),
			array('%d')
		);

		if ($updated === false) {
			wp_send_json_error(array('message' => 'db_error'), 500);
		}

		wp_send_json_success(array('note' => $note));
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	private static function is_product_edit_screen($hook) {
		if ('post.php' !== $hook && 'post-new.php' !== $hook) {
			return false;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && 'product' === $screen->post_type) {
			return true;
		}
		// Fallback for early-running enqueues where get_current_screen() may be unreliable.
		global $post_type;
		return 'product' === $post_type;
	}
}
