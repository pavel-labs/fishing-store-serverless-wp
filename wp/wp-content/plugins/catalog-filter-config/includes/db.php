<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cfc_create_tables(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql_filters = "CREATE TABLE {$wpdb->prefix}cfc_filters (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id  BIGINT UNSIGNED NOT NULL,
        filters      LONGTEXT NOT NULL,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY category_id (category_id)
    ) $charset;";

    $sql_history = "CREATE TABLE {$wpdb->prefix}cfc_history (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        category_id  BIGINT UNSIGNED NOT NULL,
        filters      LONGTEXT NOT NULL,
        user_id      BIGINT UNSIGNED NOT NULL,
        user_name    VARCHAR(250) NOT NULL,
        changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note         VARCHAR(500) DEFAULT '',
        PRIMARY KEY  (id),
        KEY idx_category_date (category_id, changed_at)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_filters );
    dbDelta( $sql_history );

    update_option( 'cfc_db_version', CFC_DB_VERSION );
}

function cfc_log_history( int $category_id, array $old_filters, string $note = '' ): void {
    if ( empty( $old_filters ) ) {
        return;
    }

    global $wpdb;
    $user = wp_get_current_user();

    $wpdb->insert( CFC_TABLE_HISTORY, [
        'category_id' => $category_id,
        'filters'     => wp_json_encode( $old_filters, JSON_UNESCAPED_UNICODE ),
        'user_id'     => $user->ID,
        'user_name'   => $user->display_name ?: $user->user_login,
        'changed_at'  => current_time( 'mysql' ),
        'note'        => sanitize_text_field( $note ),
    ] );

    $wpdb->query( $wpdb->prepare(
        'DELETE FROM ' . CFC_TABLE_HISTORY . '
         WHERE category_id = %d
           AND id NOT IN (
               SELECT id FROM (
                   SELECT id FROM ' . CFC_TABLE_HISTORY . '
                   WHERE category_id = %d
                   ORDER BY changed_at DESC
                   LIMIT 20
               ) t
           )',
        $category_id,
        $category_id
    ) );
}

function cfc_get_history( int $category_id ): array {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        'SELECT id, user_name, changed_at, note,
                LENGTH(filters) as filter_count
         FROM ' . CFC_TABLE_HISTORY . '
         WHERE category_id = %d
         ORDER BY changed_at DESC
         LIMIT 20',
        $category_id
    ), ARRAY_A ) ?: [];
}

function cfc_get_history_version( int $history_id ): ?array {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        'SELECT filters FROM ' . CFC_TABLE_HISTORY . ' WHERE id = %d',
        $history_id
    ), ARRAY_A );

    return $row ? json_decode( $row['filters'], true ) : null;
}

function cfc_schedule_cache_cleanup(): void {
    if ( ! wp_next_scheduled( 'cfc_cache_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'cfc_cache_cleanup' );
    }
}

add_action( 'cfc_cache_cleanup', function () {
    global $wpdb;
    $wpdb->query( 'DELETE FROM ' . CFC_TABLE_HISTORY . " WHERE changed_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );
} );
