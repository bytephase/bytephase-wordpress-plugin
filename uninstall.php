<?php

/**
 * Removes all plugin data on uninstall.
 *
 * @package BytePhase\Connector
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Closure keeps every variable local — uninstall.php runs at file scope.
(static function (): void {
    foreach (
        [
            'bytephase_connector_base_url',
            'bytephase_connector_tenant',
            'bytephase_connector_api_key',
            'bytephase_connector_activity',
            'bytephase_connector_auth_failed',
            'bytephase_connector_destinations',
        ] as $option
    ) {
        delete_option($option);
    }

    wp_clear_scheduled_hook('bytephase_connector_retry');

    global $wpdb;
    $table = $wpdb->prefix . 'bytephase_pending_submissions';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name cannot be a prepared placeholder; it is a literal on $wpdb->prefix, never user input.
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
})();
