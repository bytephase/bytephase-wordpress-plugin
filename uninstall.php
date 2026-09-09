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
            'bytephase_connector_custom_fields',
            // Index of the form types whose definitions were cached.
            'bytephase_connector_cf_index',
        ] as $option
    ) {
        delete_option($option);
    }

    // Cached custom field definitions: one transient per form type, plus the type list.
    delete_transient('bytephase_connector_cf_types');

    global $wpdb;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients have no wildcard API; this is the documented way to clear a family of them on uninstall.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_bytephase_connector_cf_') . '%',
            $wpdb->esc_like('_transient_timeout_bytephase_connector_cf_') . '%',
        )
    );

    wp_clear_scheduled_hook('bytephase_connector_retry');

    $table = $wpdb->prefix . 'bytephase_pending_submissions';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- a table name cannot be a prepared placeholder; it is a literal on $wpdb->prefix, never user input.
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
})();
