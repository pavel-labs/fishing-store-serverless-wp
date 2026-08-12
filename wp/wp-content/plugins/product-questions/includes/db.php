<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pq_create_table(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE " . PQ_TABLE . " (
        id          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id  bigint(20) UNSIGNED NOT NULL,
        user_id     bigint(20) UNSIGNED NOT NULL DEFAULT 0,
        user_name   varchar(200)        NOT NULL DEFAULT '',
        question    text                NOT NULL,
        answer      text                         DEFAULT NULL,
        status      varchar(20)         NOT NULL DEFAULT 'pending',
        created_at  datetime            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        answered_at datetime                     DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_product_status (product_id, status)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'pq_db_version', PQ_DB_VERSION );
}
