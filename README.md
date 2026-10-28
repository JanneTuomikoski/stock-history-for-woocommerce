# Stock History for WooCommerce

Audit log of every WooCommerce stock change - manual edits, order reductions, and order restorations. Adds a **Stock History** tab to the product edit screen with inline notes.

## What it records

Every change to a product's stock level is written to a custom table with the date, delta, new stock, source, and the user who triggered it. Variations with their own stock are tracked separately. When variations inherit stock management, changes belong to the parent product's shared inventory and that shared history is shown in their previews.

Sources:

| Source         | When it fires                                                                |
| -------------- | ---------------------------------------------------------------------------- |
| `manual`       | Admin UI, REST API, or any direct call through `WC_Product::set_stock_quantity()` |
| `order_reduce` | Stock reduction when an order is placed / transitions to a reducing status   |
| `order_restore`| Stock restoration when an order is cancelled or refunded                     |
| `order_adjust` | Stock adjustment when an order line quantity changes or the line is removed |

## Features

- Stock History tab in the WooCommerce Product Data box
- Per-variation history preview under each variation, with a link to its full paginated history
- Inline-editable notes on any history entry (500 char limit)
- Order entries link back to the related order
- UTC storage, site-timezone display

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate **Stock History for WooCommerce** through the **Plugins** menu.
3. Edit any product - the **Stock History** tab appears in the Product Data box.

## Data storage

History rows live in a custom table, `{prefix}shfw_history`, created on activation. The table is dropped on uninstall (not on deactivation).

Schema setup is verified before its version is saved and retries on subsequent requests if setup fails. If a history write fails, the stock update can still complete: its original audit event is queued in a separate non-autoloaded WordPress option and retried by the `shfw_retry_history` cron event. A unique event key prevents duplicate rows if a retry loses its acknowledgement. Recovery does not recreate history for deleted products.

Administrators see a persistent warning while logging needs attention. Correct database permissions/storage errors and allow WordPress cron to run, or run `wp cron event run shfw_retry_history`. A warning marked unrecoverable means even the recovery event could not be saved; investigate the server logs and any missing history before acknowledging it with `wp option delete shfw_logging_health`. Missing historical events are not fabricated or backfilled.

Schema:

```sql
CREATE TABLE {prefix}shfw_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  BIGINT UNSIGNED NOT NULL,
    qty_before  DOUBLE NULL,
    qty_after   DOUBLE NOT NULL,
    qty_delta   DOUBLE NOT NULL,
    source      VARCHAR(32) NOT NULL,
    source_ref  BIGINT UNSIGNED NULL,
    user_id     BIGINT UNSIGNED NULL,
    note        TEXT NULL,
    created_at  DATETIME NOT NULL,
    event_key   CHAR(36) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY event_key (event_key),
    KEY product_id (product_id),
    KEY created_at (created_at),
    KEY product_created (product_id, created_at)
);
```

## Translations

UI strings are translatable via the `stock-history-for-woocommerce` text domain. The translation template lives at `languages/stock-history-for-woocommerce.pot`.

To translate, generate a `.po` / `.mo` pair using [Poedit](https://poedit.net/) or [Loco Translate](https://wordpress.org/plugins/loco-translate/), then drop them in `wp-content/languages/plugins/` as `stock-history-for-woocommerce-{locale}.po`/`.mo`.

## Security model

- All admin output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- All SQL goes through `$wpdb->prepare()` with typed placeholders.
- The AJAX note endpoint checks a nonce, the `edit_products` capability, **and** `edit_post` on the specific product the entry belongs to (closes IDOR across products).
- Server-side length cap on notes (500 chars) regardless of client-side `maxlength`.

## FAQ

**Does it backfill historical changes from before the plugin was installed?**
No. Only changes made after activation are recorded.

**Are variations tracked?**
Yes. Independently managed variations have their own history. Inherited stock is recorded against the parent inventory and shown as shared history in the variation editor.

**Can I export the history?**
Not in the plugin UI yet. The data lives in a regular database table and can be read with any tool that talks to your WordPress database.

## Changelog

### 1.0.1
- Record order line quantity edits and removals with their order reference.
- Retain genuine repeated stock events instead of suppressing them by time/quantity.
- Add variation previews and permission-checked pagination; resolve shared inventory to its stock owner.
- Use WooCommerce order edit URLs for HPOS and legacy storage, with order permissions checked.
- Apply Unicode-safe note limits and preserve drafts after failed saves.
- Verify schema installation, queue failed history writes, and retry them idempotently with a persistent admin health warning.
- Add PHP hook/persistence regressions and real DOM note editor checks. WooCommerce 9.1+ is required for the per-item restoration hook.

### 1.0.0
- Initial release.

## License

GPL v2 or later. See [LICENSE](LICENSE).
