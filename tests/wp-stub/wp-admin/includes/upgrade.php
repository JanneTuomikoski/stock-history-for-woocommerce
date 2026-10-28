<?php
function dbDelta($sql) {
    ++$GLOBALS['dbDeltaCalls'];
    if ($GLOBALS['wpdb']->failSchema) { $GLOBALS['wpdb']->last_error = 'Injected CREATE failure'; return array(); }
    $GLOBALS['wpdb']->schemaColumns = array('id', 'product_id', 'qty_before', 'qty_after', 'qty_delta', 'source', 'source_ref', 'user_id', 'note', 'created_at', 'event_key');
    return array('Created history table');
}
