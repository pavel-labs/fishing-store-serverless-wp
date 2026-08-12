<?php
/**
 * Plugin Name:  Product Questions
 * Description:  Q&A for products: customers ask questions, managers answer.
 *               REST API: GET/POST /wp-json/fishing/v1/questions
 *               Answers are posted via WP Admin → WooCommerce → Product Questions.
 * Version:      1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author:       Fishing Store Team
 * License:      GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PQ_VERSION',    '1.0.0' );
define( 'PQ_DIR',        plugin_dir_path( __FILE__ ) );
define( 'PQ_DB_VERSION', 1 );

global $wpdb;
define( 'PQ_TABLE', $wpdb->prefix . 'product_questions' );

require_once PQ_DIR . 'includes/db.php';
require_once PQ_DIR . 'includes/rest-api.php';
require_once PQ_DIR . 'includes/admin-page.php';

register_activation_hook( __FILE__, 'pq_activate' );
function pq_activate(): void {
    pq_create_table();
}

add_action( 'plugins_loaded', function (): void {
    if ( (int) get_option( 'pq_db_version', 0 ) < PQ_DB_VERSION ) {
        pq_create_table();
    }
} );

add_action( 'admin_menu', function (): void {
    add_submenu_page(
        'woocommerce',
        'Product Questions',
        'Product Questions',
        'manage_woocommerce',
        'product-questions',
        'pq_render_admin_page'
    );
} );
