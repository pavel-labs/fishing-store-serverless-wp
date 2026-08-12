<?php
/**
 * Plugin Name: Catalog Filter Config
 * Description: Per-category catalog filter configuration for WooCommerce, exposed to a Next.js storefront via REST. Import/export, diagnostics and webhook revalidation included.
 * Version:     2.3.0
 * Author:      Fishing Store
 * Text Domain: catalog-filter-config
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CFC_VERSION',      '2.3.0' );
define( 'CFC_DIR',          plugin_dir_path( __FILE__ ) );
define( 'CFC_URL',          plugin_dir_url( __FILE__ ) );
define( 'CFC_DB_VERSION',   1 );
define( 'CFC_CACHE_PREFIX', 'cfc_' );
define( 'CFC_CACHE_TTL',    300 );

// Table names depend on $wpdb->prefix — resolved once at plugin load.
global $wpdb;
define( 'CFC_TABLE_FILTERS', $wpdb->prefix . 'cfc_filters' );
define( 'CFC_TABLE_HISTORY', $wpdb->prefix . 'cfc_history' );

require_once CFC_DIR . 'includes/db.php';
require_once CFC_DIR . 'includes/helpers.php';
require_once CFC_DIR . 'includes/ajax.php';
require_once CFC_DIR . 'includes/rest-api.php';
require_once CFC_DIR . 'includes/import.php';
require_once CFC_DIR . 'includes/webhooks.php';
require_once CFC_DIR . 'includes/admin-page.php';

register_activation_hook( __FILE__, 'cfc_activate' );

function cfc_activate(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'Catalog Filter Config requires WooCommerce to be installed and active.', 'cfc' ),
            'Plugin dependency check',
            [ 'back_link' => true ]
        );
    }
    cfc_create_tables();
    cfc_schedule_cache_cleanup();
}

register_deactivation_hook( __FILE__, 'cfc_deactivate' );

function cfc_deactivate(): void {
    wp_clear_scheduled_hook( 'cfc_cache_cleanup' );
}

// ─── DB migration check ──────────────────────────────────────────────────────

add_action( 'plugins_loaded', function () {
    $installed_version = (int) get_option( 'cfc_db_version', 0 );
    if ( $installed_version < CFC_DB_VERSION ) {
        cfc_create_tables();
    }
} );


add_action( 'admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'Catalog Filters',
        'Catalog Filters',
        'manage_woocommerce',
        'catalog-filter-config',
        'cfc_render_admin_page'
    );
} );


add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'woocommerce_page_catalog-filter-config' ) {
        return;
    }

    wp_enqueue_script(
        'sortablejs',
        CFC_URL . 'assets/sortable.min.js',
        [],
        '1.15.0',
        true
    );

    wp_enqueue_script(
        'sheetjs',
        CFC_URL . 'assets/xlsx.full.min.js',
        [],
        '0.18.5',
        true
    );

    wp_enqueue_script(
        'cfc-admin',
        CFC_URL . 'assets/admin.js',
        [ 'jquery', 'sortablejs', 'sheetjs' ],
        CFC_VERSION,
        true
    );

    wp_enqueue_style(
        'cfc-admin',
        CFC_URL . 'assets/admin.css',
        [],
        CFC_VERSION
    );

    wp_localize_script( 'cfc-admin', 'CFC', [
        'nonce'       => wp_create_nonce( 'cfc_nonce' ),
        'ajax_url'    => admin_url( 'admin-ajax.php' ),
        'rest_url'    => rest_url( 'catalog/v1/' ),
        'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
        'site_url'    => get_site_url(),
        'wc_attrs'    => cfc_get_wc_attributes(),
        'wc_cats'     => cfc_get_all_categories_flat(),
    ] );
} );


function cfc_get_wc_attributes(): array {
    if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) return [];
    $taxonomies = wc_get_attribute_taxonomies();
    return array_values( array_map( fn( $t ) => [
        'slug'  => wc_attribute_taxonomy_name( $t->attribute_name ),
        'label' => $t->attribute_label,
    ], $taxonomies ?: [] ) );
}

function cfc_get_all_categories_flat(): array {
    $terms = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
    ] );
    if ( is_wp_error( $terms ) ) return [];

    return array_values( array_map( fn( $t ) => [
        'id'     => $t->term_id,
        'name'   => $t->name,
        'slug'   => $t->slug,
        'parent' => $t->parent,
        'count'  => $t->count,
    ], $terms ) );
}

function cfc_get_category_filters( int $category_id, int $depth = 0 ): array {
    if ( $depth > 10 ) {
        return [];
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare( 'SELECT filters FROM ' . CFC_TABLE_FILTERS . ' WHERE category_id = %d', $category_id ),
        ARRAY_A
    );

    if ( $row ) {
        return json_decode( $row['filters'], true ) ?: [];
    }

    $term = get_term( $category_id, 'product_cat' );
    if ( $term && ! is_wp_error( $term ) && $term->parent ) {
        return cfc_get_category_filters( $term->parent, $depth + 1 );
    }

    return [];
}

function cfc_has_own_filters( int $category_id ): bool {
    global $wpdb;
    return (bool) $wpdb->get_var(
        $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CFC_TABLE_FILTERS . ' WHERE category_id = %d', $category_id )
    );
}

function cfc_save_category_filters( int $category_id, array $filters ): bool {
    global $wpdb;

    usort( $filters, fn( $a, $b ) => ( $a['order'] ?? 10 ) - ( $b['order'] ?? 10 ) );

    $result = $wpdb->replace( CFC_TABLE_FILTERS, [
        'category_id' => $category_id,
        'filters'     => wp_json_encode( $filters, JSON_UNESCAPED_UNICODE ),
        'updated_at'  => current_time( 'mysql' ),
    ] );

    if ( $result !== false ) {
        delete_transient( CFC_CACHE_PREFIX . 'filters_' . $category_id );
        $term = get_term( $category_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            delete_transient( 'cfc_rest_' . $term->slug );
        }
        wp_cache_delete( 'cfc_product_ids_' . $category_id );
        cfc_notify_nextjs( $category_id );
        return true;
    }

    return false;
}

function cfc_delete_category_filters( int $category_id ): void {
    global $wpdb;
    $wpdb->delete( CFC_TABLE_FILTERS, [ 'category_id' => $category_id ] );
    delete_transient( CFC_CACHE_PREFIX . 'filters_' . $category_id );
    $term = get_term( $category_id, 'product_cat' );
    if ( $term && ! is_wp_error( $term ) ) {
        delete_transient( 'cfc_rest_' . $term->slug );
    }
}
