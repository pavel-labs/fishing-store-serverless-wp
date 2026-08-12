<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {

    // GET /catalog/v1/filters/{slug} — filters for a single category
    register_rest_route( 'catalog/v1', '/filters/(?P<slug>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'cfc_rest_get_filters',
        'permission_callback' => '__return_true',
        'args'                => [
            'slug' => [ 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
        ],
    ] );

    // POST /catalog/v1/filters/batch — filters for several categories
    register_rest_route( 'catalog/v1', '/filters/batch', [
        'methods'             => 'POST',
        'callback'            => 'cfc_rest_batch_filters',
        'permission_callback' => '__return_true',
    ] );

    // GET /catalog/v1/filters/batch?slugs=slug1,slug2 — GET alternative
    register_rest_route( 'catalog/v1', '/filters/batch', [
        'methods'             => 'GET',
        'callback'            => 'cfc_rest_batch_filters_get',
        'permission_callback' => '__return_true',
    ] );

    // GET /catalog/v1/attributes — all WC attributes (public)
    register_rest_route( 'catalog/v1', '/attributes', [
        'methods'             => 'GET',
        'callback'            => 'cfc_rest_get_attributes',
        'permission_callback' => '__return_true',
    ] );

    // GraphQL-style endpoint — flexible queries
    register_rest_route( 'catalog/v1', '/query', [
        'methods'             => 'POST',
        'callback'            => 'cfc_rest_query',
        'permission_callback' => '__return_true',
    ] );

} );

// ─── Single slug ──────────────────────────────────────────────────────────────

function cfc_rest_get_filters( WP_REST_Request $request ): WP_REST_Response {
    $slug = $request->get_param( 'slug' );

    $cache_key = 'cfc_rest_' . $slug;
    $cached    = get_transient( $cache_key );
    if ( $cached !== false ) {
        return new WP_REST_Response( $cached, 200, cfc_cache_headers() );
    }

    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( ! $term ) {
        return new WP_REST_Response( [
            'filters'     => [],
            'inherited'   => false,
            'category'    => null,
            'price_range' => null,
        ], 200 );
    }

    $data = cfc_build_category_filters_response( $term, $slug );

    set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
    return new WP_REST_Response( $data, 200, cfc_cache_headers() );
}

/**
 * Build the filters response for a single category term, falling back to the
 * default filter set when nothing is configured in the admin.
 */
function cfc_build_category_filters_response( WP_Term $term, string $slug ): array {
    $own     = cfc_get_own_filters( $term->term_id );
    $filters = cfc_get_category_filters( $term->term_id );

    // Sort by order
    usort( $filters, fn( $a, $b ) => ( $a['order'] ?? 10 ) - ( $b['order'] ?? 10 ) );

    // Enrich — pull options from WC, real prices
    $enriched    = cfc_enrich_filters( $filters, $term->term_id );
    $price_range = cfc_resolve_price_range( $term->term_id );

    // No configured filters — serve sensible defaults so the storefront works.
    if ( empty( $enriched ) ) {
        $enriched = cfc_get_default_filters( $price_range );
    }

    return [
        'filters'     => $enriched,
        'inherited'   => empty( $own ),
        'price_range' => $price_range,
        'category'    => [
            'id'    => $term->term_id,
            'name'  => $term->name,
            'slug'  => $slug,
            'count' => $term->count,
        ],
    ];
}

// ─── Batch — several slugs at once ────────────────────────────────────────────

function cfc_rest_batch_filters( WP_REST_Request $request ): WP_REST_Response {
    $body  = $request->get_json_params();
    $slugs = array_map( 'sanitize_text_field', (array) ( $body['slugs'] ?? [] ) );
    return cfc_build_batch_response( $slugs );
}

function cfc_rest_batch_filters_get( WP_REST_Request $request ): WP_REST_Response {
    $raw   = $request->get_param( 'slugs' ) ?? '';
    $slugs = array_map( 'sanitize_text_field', explode( ',', $raw ) );
    return cfc_build_batch_response( $slugs );
}

function cfc_build_batch_response( array $slugs ): WP_REST_Response {
    if ( empty( $slugs ) || count( $slugs ) > 50 ) {
        return new WP_REST_Response( [ 'error' => 'Provide between 1 and 50 slugs' ], 400 );
    }

    $result = [];
    foreach ( $slugs as $slug ) {
        if ( ! $slug ) continue;

        $cache_key = 'cfc_rest_' . $slug;
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            $result[ $slug ] = $cached;
            continue;
        }

        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term ) {
            $result[ $slug ] = [ 'filters' => [], 'inherited' => false, 'category' => null, 'price_range' => null ];
            continue;
        }

        $data = cfc_build_category_filters_response( $term, $slug );

        set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
        $result[ $slug ] = $data;
    }

    return new WP_REST_Response( [ 'results' => $result ], 200, cfc_cache_headers() );
}

// ─── GraphQL-style query ──────────────────────────────────────────────────────

function cfc_rest_query( WP_REST_Request $request ): WP_REST_Response {
    $body = $request->get_json_params();

    /**
     * Simplified JSON body with the following fields:
     * { "fields": ["filters", "category", "productCount"], "slug": "reels" }
     */
    $slug           = sanitize_text_field( $body['slug'] ?? '' );
    $allowed_fields = [ 'filters', 'category', 'productCount', 'inherited' ];
    $fields         = array_intersect( (array) ( $body['fields'] ?? [ 'filters', 'category' ] ), $allowed_fields );
    $slugs          = array_map( 'sanitize_text_field', (array) ( $body['slugs'] ?? [] ) );

    if ( $slug ) {
        $slugs = [ $slug ];
    }

    if ( empty( $slugs ) ) {
        return new WP_REST_Response( [ 'error' => 'Provide a slug or slugs' ], 400 );
    }

    $result = [];

    foreach ( $slugs as $s ) {
        $term = get_term_by( 'slug', $s, 'product_cat' );
        if ( ! $term ) {
            $result[ $s ] = null;
            continue;
        }

        $item = [];

        if ( in_array( 'filters', $fields, true ) ) {
            $filters = cfc_get_category_filters( $term->term_id );
            $active  = array_values( array_filter( $filters, fn( $f ) => ! empty( $f['enabled'] ) ) );
            usort( $active, fn( $a, $b ) => ( $a['order'] ?? 10 ) - ( $b['order'] ?? 10 ) );
            // Selective subfields
            $filter_fields = (array) ( $body['filterFields'] ?? null );
            if ( $filter_fields ) {
                $active = array_map( fn( $f ) => array_intersect_key(
                    $f, array_flip( $filter_fields )
                ), $active );
            }
            $item['filters'] = $active;
        }

        if ( in_array( 'category', $fields, true ) ) {
            $item['category'] = [
                'id'     => $term->term_id,
                'name'   => $term->name,
                'slug'   => $s,
                'parent' => $term->parent,
                'count'  => $term->count,
            ];
        }

        if ( in_array( 'productCount', $fields, true ) ) {
            $item['productCount'] = (int) $term->count;
        }

        if ( in_array( 'inherited', $fields, true ) ) {
            $item['inherited'] = ! cfc_has_own_filters( $term->term_id );
        }

        $result[ $s ] = $item;
    }

    $response = count( $slugs ) === 1
        ? ( $result[ $slugs[0] ] ?? null )
        : [ 'results' => $result ];

    return new WP_REST_Response( $response, 200, cfc_cache_headers() );
}

// ─── Attributes ───────────────────────────────────────────────────────────────

function cfc_rest_get_attributes( WP_REST_Request $request ): WP_REST_Response {
    $attrs = cfc_get_wc_attributes();
    return new WP_REST_Response( $attrs, 200, cfc_cache_headers() );
}

// ─── Cache headers ────────────────────────────────────────────────────────────

function cfc_cache_headers(): array {
    return [
        'Cache-Control' => 'public, max-age=300, stale-while-revalidate=60',
        'X-CFC-Version' => CFC_VERSION,
    ];
}
