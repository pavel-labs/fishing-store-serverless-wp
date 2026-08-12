<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cfc_notify_nextjs( int $category_id ): void {
    $webhook_url    = get_option( 'cfc_webhook_url', '' );
    $webhook_secret = get_option( 'cfc_webhook_secret', '' );

    if ( ! $webhook_url ) {
        return;
    }

    $term = get_term( $category_id, 'product_cat' );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }

    $payload = [
        'event'       => 'catalog_filters_updated',
        'category_id' => $category_id,
        'slug'        => $term->slug,
        'timestamp'   => time(),
    ];

    $signature = hash_hmac( 'sha256', wp_json_encode( $payload ), $webhook_secret );

    wp_remote_post( $webhook_url, [
        'body'        => wp_json_encode( $payload ),
        'headers'     => [
            'Content-Type'    => 'application/json',
            'X-CFC-Signature' => $signature,
            'X-CFC-Version'   => CFC_VERSION,
        ],
        'timeout'     => 5,
        'blocking'    => false, // fire-and-forget
        'data_format' => 'body',
    ] );

    if ( get_option( 'cfc_webhook_log', false ) ) {
        $log = get_option( 'cfc_webhook_log_entries', [] );
        array_unshift( $log, [
            'time'     => current_time( 'mysql' ),
            'category' => $term->name,
            'url'      => $webhook_url,
        ] );
        update_option( 'cfc_webhook_log_entries', array_slice( $log, 0, 50 ), false );
    }
}


add_action( 'wp_ajax_cfc_test_webhook', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $url    = sanitize_url( wp_unslash( $_POST['url'] ?? '' ) );
    $secret = sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) );

    if ( ! $url ) {
        wp_send_json_error( 'URL not specified' );
    }

    $payload = [
        'event'     => 'test',
        'message'   => 'Test request from Catalog Filter Config',
        'timestamp' => time(),
    ];

    $signature = hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );

    $response = wp_remote_post( $url, [
        'body'    => wp_json_encode( $payload ),
        'headers' => [
            'Content-Type'    => 'application/json',
            'X-CFC-Signature' => $signature,
        ],
        'timeout' => 10,
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Error: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( $code >= 200 && $code < 300 ) {
        wp_send_json_success( "Webhook works (HTTP {$code})" );
    } else {
        wp_send_json_error( "Server returned HTTP {$code}" );
    }
} );


add_action( 'wp_ajax_cfc_save_webhook_settings', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    update_option( 'cfc_webhook_url',    sanitize_url( wp_unslash( $_POST['url'] ?? '' ) ) );
    update_option( 'cfc_webhook_secret', sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) ) );
    update_option( 'cfc_webhook_log',    ! empty( $_POST['log'] ) );

    wp_send_json_success( 'Settings saved' );
} );
