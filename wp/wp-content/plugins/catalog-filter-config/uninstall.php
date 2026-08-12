<?php
/**
 * Uninstall handler for Catalog Filter Config.
 *
 * If the admin chose "Delete all data on uninstall" in Settings,
 * removes tables, options and transients. Otherwise leaves data intact
 * so reinstalling the plugin restores the previous configuration.
 *
 * @package CatalogFilterConfig
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$delete_data = get_option( 'cfc_delete_on_uninstall', false );

// Always remove the setting itself so it doesn't linger.
delete_option( 'cfc_delete_on_uninstall' );

if ( ! $delete_data ) {
    // User chose to keep data — nothing else to do.
    return;
}

// ── Drop custom tables ──────────────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cfc_filters" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cfc_history" );

// ── Delete options ──────────────────────────────────────────────────────────
$options = [
    'cfc_db_version',
    'cfc_webhook_url',
    'cfc_webhook_secret',
    'cfc_webhook_log',
    'cfc_webhook_log_entries',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ── Delete transients ───────────────────────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_cfc_%'
        OR option_name LIKE '_transient_timeout_cfc_%'"
);

// ── Clear scheduled events ──────────────────────────────────────────────────
wp_clear_scheduled_hook( 'cfc_cache_cleanup' );
