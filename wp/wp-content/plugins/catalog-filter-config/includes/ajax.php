<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


add_action( 'wp_ajax_cfc_save_filters', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $category_id = absint( $_POST['category_id'] ?? 0 );
    if ( ! $category_id ) {
        wp_send_json_error( 'No category specified' );
    }

    $old = cfc_get_own_filters( $category_id );
    if ( $old ) {
        cfc_log_history( $category_id, $old, sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ) );
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in cfc_sanitize_filters()
    $raw     = wp_unslash( $_POST['filters'] ?? [] );
    $filters = cfc_sanitize_filters( is_array( $raw ) ? $raw : [] );

    if ( cfc_save_category_filters( $category_id, $filters ) ) {
        wp_send_json_success( [
            'count'   => count( $filters ),
            'history' => cfc_get_history( $category_id ),
        ] );
    } else {
        wp_send_json_error( 'Database write error' );
    }
} );


add_action( 'wp_ajax_cfc_reset_category', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $category_id = absint( $_POST['category_id'] ?? 0 );
    $old         = cfc_get_own_filters( $category_id );
    if ( $old ) {
        cfc_log_history( $category_id, $old, 'Reset to inheritance' );
    }
    cfc_delete_category_filters( $category_id );
    wp_send_json_success();
} );


add_action( 'wp_ajax_cfc_restore_history', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $history_id  = absint( $_POST['history_id'] ?? 0 );
    $category_id = absint( $_POST['category_id'] ?? 0 );

    $filters = cfc_get_history_version( $history_id );
    if ( ! $filters ) { wp_send_json_error( 'Version not found' ); }

    $old = cfc_get_own_filters( $category_id );
    if ( $old ) {
        cfc_log_history( $category_id, $old, 'Rollback to a previous version' );
    }

    cfc_save_category_filters( $category_id, $filters );
    wp_send_json_success( [
        'filters' => $filters,
        'count'   => count( $filters ),
    ] );
} );


add_action( 'wp_ajax_cfc_copy_filters', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $from_id     = absint( $_POST['from_id'] ?? 0 );
    $to_ids      = array_map( 'absint', (array) ( $_POST['to_ids'] ?? [] ) );
    $overwrite   = ! empty( $_POST['overwrite'] );

    if ( ! $from_id || empty( $to_ids ) ) {
        wp_send_json_error( 'Specify the source and target categories' );
    }

    $source_filters = cfc_get_own_filters( $from_id );
    if ( empty( $source_filters ) ) {
        wp_send_json_error( 'The source category has no custom settings' );
    }

    $copied  = 0;
    $skipped = 0;

    foreach ( $to_ids as $to_id ) {
        if ( $to_id === $from_id ) continue;
        if ( ! $overwrite && cfc_has_own_filters( $to_id ) ) {
            $skipped++;
            continue;
        }
        cfc_save_category_filters( $to_id, $source_filters );
        $copied++;
    }

    wp_send_json_success( [
        'copied'  => $copied,
        'skipped' => $skipped,
    ] );
} );


add_action( 'wp_ajax_cfc_bulk_toggle', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $category_id  = absint( $_POST['category_id'] ?? 0 );
    $filter_index = absint( $_POST['filter_index'] ?? -1 );
    $enabled      = ! empty( $_POST['enabled'] );

    if ( $filter_index < 0 ) {
        $filters = cfc_get_own_filters( $category_id );
        $filters = array_map( function ( $f ) use ( $enabled ) {
            $f['enabled'] = $enabled;
            return $f;
        }, $filters );
    } else {
        $filters = cfc_get_own_filters( $category_id );
        if ( isset( $filters[ $filter_index ] ) ) {
            $filters[ $filter_index ]['enabled'] = $enabled;
        }
    }

    cfc_save_category_filters( $category_id, $filters );
    wp_send_json_success( [ 'filters' => $filters ] );
} );


add_action( 'wp_ajax_cfc_preview_filters', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $category_id = absint( $_POST['category_id'] ?? 0 );
    if ( ! $category_id ) { wp_send_json_error( 'No category' ); }

    $filters      = cfc_get_category_filters( $category_id );
    $active       = array_filter( $filters, fn( $f ) => ! empty( $f['enabled'] ) );
    $preview_data = [];

    foreach ( $active as $filter ) {
        $item = [
            'name'    => $filter['display_name'],
            'type'    => $filter['type'],
            'enabled' => true,
        ];

        if ( $filter['type'] === 'range' && $filter['attr_slug'] === 'price' ) {
            $args = [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [ [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ] ],
            ];
            $products = get_posts( $args );

            $prices = array_filter( array_map( function ( $id ) {
                $p = wc_get_product( $id );
                return $p ? (float) $p->get_price() : null;
            }, $products ) );

            $item['min']       = $prices ? (int) min( $prices ) : (int) $filter['range_min'];
            $item['max']       = $prices ? (int) max( $prices ) : (int) $filter['range_max'];
            $item['current']   = [ $item['min'], $item['max'] ];
            $item['count']     = count( $products );

        } elseif ( $filter['type'] === 'checkbox' && ! empty( $filter['attr_slug'] ) ) {
            $taxonomy = $filter['attr_slug'];
            $terms    = get_terms( [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'object_ids' => cfc_get_product_ids_for_category( $category_id ),
            ] );

            $item['options']        = [];
            $item['product_counts'] = [];

            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $item['options'][]        = $term->name;
                    $item['product_counts'][] = $term->count;
                }
            }
            $item['total_terms'] = count( $item['options'] );

        } elseif ( $filter['type'] === 'range' ) {
            $item['min']     = (float) ( $filter['range_min'] ?? 0 );
            $item['max']     = (float) ( $filter['range_max'] ?? 9999 );
            $item['current'] = [ $item['min'], $item['max'] ];
        }

        if ( ! empty( $filter['min_products'] ) ) {
            $item['min_products_condition'] = (int) $filter['min_products'];
        }

        $preview_data[] = $item;
    }

    $term     = get_term( $category_id, 'product_cat' );
    $products = wc_get_products( [
        'category' => [ $term->slug ],
        'limit'    => -1,
        'return'   => 'ids',
    ] );

    wp_send_json_success( [
        'filters'       => $preview_data,
        'product_count' => is_array( $products ) ? count( $products ) : 0,
        'category_name' => $term->name ?? '',
    ] );
} );


add_action( 'wp_ajax_cfc_get_history', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied' ); }

    $category_id = absint( $_POST['category_id'] ?? 0 );
    wp_send_json_success( cfc_get_history( $category_id ) );
} );


add_action( 'wp_ajax_cfc_run_diagnostics', function (): void {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    global $wpdb;

    $total_configured = (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . CFC_TABLE_FILTERS
    );

    $total_filters = (int) $wpdb->get_var(
        "SELECT SUM( JSON_LENGTH( filters ) ) FROM " . CFC_TABLE_FILTERS
    );

    $all_categories = get_terms( [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'fields'     => 'ids',
    ] );
    $total_categories = is_array( $all_categories ) ? count( $all_categories ) : 0;

    $known_slugs = [ 'price' ];
    if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
        foreach ( wc_get_attribute_taxonomies() ?: [] as $tax ) {
            $known_slugs[] = wc_attribute_taxonomy_name( $tax->attribute_name );
        }
    }

    $broken     = [];
    $orphaned   = [];
    $all_rows   = $wpdb->get_results(
        'SELECT category_id, filters FROM ' . CFC_TABLE_FILTERS,
        ARRAY_A
    );

    foreach ( $all_rows as $row ) {
        $cat_id  = (int) $row['category_id'];
        $filters = json_decode( $row['filters'], true ) ?: [];

        $term = get_term( $cat_id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            $orphaned[] = [
                'category_id'   => $cat_id,
                'filters_count' => count( $filters ),
            ];
            continue;
        }

        foreach ( $filters as $f ) {
            $slug = $f['attr_slug'] ?? '';
            if ( $slug && ! in_array( $slug, $known_slugs, true ) && ! taxonomy_exists( $slug ) ) {
                $broken[] = [
                    'category_id'   => $cat_id,
                    'category_name' => $term->name,
                    'filter_name'   => $f['display_name'] ?? '?',
                    'attr_slug'     => $slug,
                ];
            }
        }
    }

    $configured_ids = array_column( $all_rows, 'category_id' );
    $inherited_count = 0;
    if ( is_array( $all_categories ) ) {
        foreach ( $all_categories as $cat_id ) {
            if ( ! in_array( (int) $cat_id, array_map( 'intval', $configured_ids ), true ) ) {
                $filters = cfc_get_category_filters( (int) $cat_id );
                if ( ! empty( $filters ) ) {
                    $inherited_count++;
                }
            }
        }
    }

    wp_send_json_success( [
        'stats' => [
            'total_categories'  => $total_categories,
            'configured'        => $total_configured,
            'inherited'         => $inherited_count,
            'total_filters'     => (int) $total_filters,
        ],
        'broken'   => $broken,
        'orphaned' => $orphaned,
    ] );
} );


add_action( 'wp_ajax_cfc_fix_orphaned', function (): void {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    global $wpdb;
    $all_rows = $wpdb->get_results(
        'SELECT category_id FROM ' . CFC_TABLE_FILTERS,
        ARRAY_A
    );

    $deleted = 0;
    foreach ( $all_rows as $row ) {
        $term = get_term( (int) $row['category_id'], 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            $wpdb->delete( CFC_TABLE_FILTERS, [ 'category_id' => $row['category_id'] ] );
            $deleted++;
        }
    }

    wp_send_json_success( [ 'deleted' => $deleted ] );
} );


add_action( 'wp_ajax_cfc_save_advanced_settings', function (): void {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    update_option( 'cfc_delete_on_uninstall', ! empty( $_POST['delete_on_uninstall'] ) );
    wp_send_json_success( 'Saved' );
} );


function cfc_get_own_filters( int $category_id ): array {
    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare( 'SELECT filters FROM ' . CFC_TABLE_FILTERS . ' WHERE category_id = %d', $category_id ),
        ARRAY_A
    );
    return $row ? ( json_decode( $row['filters'], true ) ?: [] ) : [];
}

/** @var string[] */
const CFC_FILTER_TYPES = [ 'checkbox', 'range', 'radio', 'toggle', 'color' ];

function cfc_sanitize_filters( array $raw ): array {
    $filters = [];
    foreach ( $raw as $f ) {
        if ( empty( $f['display_name'] ) ) {
            continue;
        }

        $type = $f['type'] ?? 'checkbox';
        if ( ! in_array( $type, CFC_FILTER_TYPES, true ) ) {
            $type = 'checkbox';
        }

        $filters[] = [
            'display_name' => sanitize_text_field( $f['display_name'] ),
            'attr_slug'    => sanitize_key( $f['attr_slug'] ?? '' ),
            'type'         => $type,
            'range_min'    => isset( $f['range_min'] ) ? floatval( $f['range_min'] ) : 0,
            'range_max'    => isset( $f['range_max'] ) ? floatval( $f['range_max'] ) : 9999,
            'order'        => absint( $f['order'] ?? 10 ),
            'enabled'      => ! empty( $f['enabled'] ),
            'min_products' => absint( $f['min_products'] ?? 0 ),
        ];
    }
    return $filters;
}

