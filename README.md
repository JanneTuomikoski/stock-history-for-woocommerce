# Stock History for WooCommerce

Audit log of every WooCommerce stock change - manual edits, order reductions, and order restorations. Adds a **Stock History** tab to the product edit screen with inline notes.

## What it records

Every change to a product's stock level is written to a custom table with the date, delta, new stock, source, and the user who triggered it. Variations are tracked separately by variation ID.

Sources:

| Source         | When it fires                                                                |
| -------------- | ---------------------------------------------------------------------------- |
| `manual`       | Admin UI, REST API, or any direct call through `WC_Product::set_stock_quantity()` |
| `order_reduce` | Stock reduction when an order is placed / transitions to a reducing status   |
| `order_restore`| Stock restoration when an order is cancelled or refunded                     |

## Features

- Stock History tab in the WooCommerce Product Data box
- Per-variation history under each variation's edit screen
- Inline-editable notes on any history entry (500 char limit)
- Order entries link back to the related order
- UTC storage, site-timezone display

## Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate **Stock History for WooCommerce** through the **Plugins** menu.
3. Edit any product - the **Stock History** tab appears in the Product Data box.

## Data storage

History rows live in a custom table, `{prefix}shfw_history`, created on activation. The table is dropped on uninstall (not on deactivation).

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
    PRIMARY KEY (id),
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
Yes - each variation has its own history under its variation ID.

**Can I export the history?**
Not in the plugin UI yet. The data lives in a regular database table and can be read with any tool that talks to your WordPress database.

## Changelog

### 1.0.0
- Initial release.

## License

GPL v2 or later. See [LICENSE](LICENSE).
