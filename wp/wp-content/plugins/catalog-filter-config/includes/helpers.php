<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared utilities for the Catalog Filter Config plugin.
 * Used by rest-api.php, ajax.php and admin-page.php.
 */

/**
 * IDs of all products in a category (cached).
 */
function cfc_get_product_ids_for_category( int $category_id ): array {
    $cache_key = 'cfc_product_ids_' . $category_id;
    $cached    = wp_cache_get( $cache_key );
    if ( $cached !== false ) {
        return $cached;
    }

    $ids = get_posts( [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => [ [
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => $category_id,
            'include_children' => true,
        ] ],
    ] );

    wp_cache_set( $cache_key, $ids, '', CFC_CACHE_TTL );
    return $ids;
}

/**
 * Resolve the options (terms) for a checkbox filter within a category context.
 *
 * @param string $taxonomy    WC taxonomy (pa_brand, pa_color, etc.)
 * @param int    $category_id WooCommerce category ID
 * @return array [{name: string, slug: string, count: int}, ...]
 */
function cfc_resolve_attribute_options( string $taxonomy, int $category_id ): array {
    if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
        return [];
    }

    $product_ids = cfc_get_product_ids_for_category( $category_id );
    if ( empty( $product_ids ) ) {
        return [];
    }

    $terms = get_terms( [
        'taxonomy'   => $taxonomy,
        'hide_empty' => true,
        'object_ids' => $product_ids,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ] );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return [];
    }

    return array_values( array_map( fn( $t ) => [
        'name'  => $t->name,
        'slug'  => $t->slug,
        'count' => (int) $t->count,
    ], $terms ) );
}

/**
 * Resolve the real price range for a category.
 * Uses a direct SQL query on the _price meta for performance.
 *
 * @param int $category_id Category ID
 * @return array{min: int, max: int}
 */
function cfc_resolve_price_range( int $category_id ): array {
    $product_ids = cfc_get_product_ids_for_category( $category_id );

    if ( empty( $product_ids ) ) {
        return [ 'min' => 0, 'max' => 0 ];
    }

    global $wpdb;

    $ids_placeholder = implode( ',', array_map( 'absint', $product_ids ) );

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are absint'd above
    $row = $wpdb->get_row(
        "SELECT FLOOR(MIN(CAST(meta_value AS DECIMAL(10,2)))) AS min_price,
                CEIL(MAX(CAST(meta_value AS DECIMAL(10,2)))) AS max_price
         FROM {$wpdb->postmeta}
         WHERE post_id IN ({$ids_placeholder})
           AND meta_key = '_price'
           AND meta_value != ''
           AND meta_value IS NOT NULL",
        ARRAY_A
    );

    if ( ! $row || $row['min_price'] === null ) {
        return [ 'min' => 0, 'max' => 0 ];
    }

    return [
        'min' => (int) $row['min_price'],
        'max' => (int) $row['max_price'],
    ];
}

/**
 * Default filters served when a category has none configured in the admin.
 * These map to Store API params the storefront supports natively (price range,
 * stock status, on-sale), so filtering works with zero configuration.
 *
 * @param array{min: int, max: int} $price_range
 * @return array Filter configs in the same shape as stored filters.
 */
function cfc_get_default_filters( array $price_range ): array {
    $filters = [];

    if ( ( $price_range['max'] ?? 0 ) > ( $price_range['min'] ?? 0 ) ) {
        $filters[] = [
            'display_name' => 'Price',
            'attr_slug'    => 'price',
            'type'         => 'range',
            'range_min'    => (int) $price_range['min'],
            'range_max'    => (int) $price_range['max'],
            'order'        => 1,
            'enabled'      => true,
            'min_products' => 0,
        ];
    }

    $filters[] = [
        'display_name' => 'In stock',
        'attr_slug'    => 'instock',
        'type'         => 'toggle',
        'range_min'    => 0,
        'range_max'    => 0,
        'order'        => 2,
        'enabled'      => true,
        'min_products' => 0,
        'options'      => [],
    ];

    $filters[] = [
        'display_name' => 'On sale',
        'attr_slug'    => 'on_sale',
        'type'         => 'toggle',
        'range_min'    => 0,
        'range_max'    => 0,
        'order'        => 3,
        'enabled'      => true,
        'min_products' => 0,
        'options'      => [],
    ];

    return $filters;
}

/**
 * Enrich the filters array with real data from WooCommerce.
 * Each checkbox filter gets an `options` array; the "price" range filter
 * gets the real min/max range.
 *
 * @param array $filters     Filters array (from the DB)
 * @param int   $category_id Category ID
 * @return array Enriched filters array
 */
function cfc_enrich_filters( array $filters, int $category_id ): array {
    $enriched    = [];
    $price_range = null;

    foreach ( $filters as $filter ) {
        if ( empty( $filter['enabled'] ) ) {
            continue;
        }

        $item = $filter;

        // Checkbox / Radio / Color → pull options from WC
        $selectable_types = [ 'checkbox', 'radio', 'color' ];
        if ( in_array( $filter['type'], $selectable_types, true ) && ! empty( $filter['attr_slug'] ) ) {
            $options               = cfc_resolve_attribute_options( $filter['attr_slug'], $category_id );
            $item['options']       = array_column( $options, 'name' );
            $item['option_slugs']  = array_column( $options, 'slug' );
            $item['option_counts'] = array_column( $options, 'count' );
        }

        // Toggle → no options, behaves as a bool on the frontend
        if ( $filter['type'] === 'toggle' ) {
            $item['options'] = [];
        }

        // Range "price" → real range
        if ( $filter['type'] === 'range' && $filter['attr_slug'] === 'price' ) {
            if ( $price_range === null ) {
                $price_range = cfc_resolve_price_range( $category_id );
            }
            $item['range_min'] = $price_range['min'];
            $item['range_max'] = $price_range['max'];
        }

        // Range on a WC attribute → derive min/max from the meta when available
        if ( $filter['type'] === 'range'
             && $filter['attr_slug'] !== 'price'
             && ! empty( $filter['attr_slug'] ) ) {
            $options      = cfc_resolve_attribute_options( $filter['attr_slug'], $category_id );
            $numeric_vals = array_filter( array_map( function ( $opt ) {
                return is_numeric( $opt['name'] ) ? (float) $opt['name'] : null;
            }, $options ) );

            if ( ! empty( $numeric_vals ) ) {
                $item['range_min'] = (int) floor( min( $numeric_vals ) );
                $item['range_max'] = (int) ceil( max( $numeric_vals ) );
            }
        }

        $enriched[] = $item;
    }

    return $enriched;
}
