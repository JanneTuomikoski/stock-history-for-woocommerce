# Regression checks

Run `php tests/run.php`. The self-contained runner needs no database, WordPress installation, mail or network. Its small WordPress/WooCommerce doubles exercise the plugin's hook and persistence boundaries.

To repeat the checks with official WooCommerce functions, set `SHFW_WC_SOURCE_DIR` to a directory containing the unmodified `wc-stock-functions.php`, `wc-order-functions.php`, and `wc-admin-functions.php` from the WooCommerce version under test, then run the same command. The first two files come from `includes/`, and the third from `includes/admin/`.

The isolated checks supplement full WordPress/WooCommerce integration testing; they do not replace it.

For browser-DOM note editor regressions, run `npm ci --ignore-scripts` and `npm test` using Node 20.19+, 22.13+, or 24+. jQuery and jsdom are development-only dependencies; the installed plugin continues to use WordPress-provided jQuery without a build step.
