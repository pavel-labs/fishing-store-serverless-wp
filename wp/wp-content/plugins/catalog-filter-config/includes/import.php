<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


add_action( 'wp_ajax_cfc_import_preview', function (): void {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $json_data = wp_unslash( $_POST['data'] ?? '' );
    $payload   = json_decode( $json_data, true );

    if ( ! is_array( $payload ) || empty( $payload['rows'] ) ) {
        wp_send_json_error( 'No data to preview' );
    }

    $rows     = array_slice( $payload['rows'], 0, 5 );
    $warnings = [];

    $known_slugs = [];
    if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
        foreach ( wc_get_attribute_taxonomies() ?: [] as $tax ) {
            $known_slugs[] = wc_attribute_taxonomy_name( $tax->attribute_name );
        }
    }
    $known_slugs[] = 'price';

    foreach ( $rows as $i => $row ) {
        $cat  = trim( $row['category'] ?? '' );
        $slug = trim( $row['attr_slug'] ?? '' );
        $row_num = $i + 2; // Excel rows start at 2

        if ( $cat && ! cfc_find_category_by_name_or_slug( $cat ) ) {
            $warnings[] = "Row {$row_num}: category \"{$cat}\" not found in WooCommerce";
        }

        if ( $slug && ! in_array( $slug, $known_slugs, true ) ) {
            $warnings[] = "Row {$row_num}: attribute \"{$slug}\" is not registered in WooCommerce";
        }
    }

    $columns = [ 'Category', 'Filter', 'Type', 'WC Attribute', 'Min', 'Max', 'Order', 'Enabled', 'Min. products' ];

    wp_send_json_success( [
        'columns'  => $columns,
        'rows'     => array_map( fn( $r ) => array_values( $r ), $rows ),
        'warnings' => $warnings,
        'total'    => count( $payload['rows'] ),
    ] );
} );


add_action( 'wp_ajax_cfc_import_csv', function (): void {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    if ( empty( $_FILES['file']['tmp_name'] ) ) {
        wp_send_json_error( 'File not uploaded' );
    }

    $tmp     = $_FILES['file']['tmp_name'];
    $mode    = sanitize_key( $_POST['mode'] ?? 'skip' );
    $dry_run = ! empty( $_POST['dry_run'] );

    $rows   = [];
    $handle = fopen( $tmp, 'r' );
    if ( ! $handle ) {
        wp_send_json_error( 'Could not open the file' );
    }

    $first_line = fgets( $handle );
    rewind( $handle );
    $delimiter  = substr_count( $first_line, ';' ) >= substr_count( $first_line, ',' ) ? ';' : ',';

    $headers = null;
    $key_map = [
        'category'             => 'category',
        'filter'               => 'filter_name',
        'filter name'          => 'filter_name',
        'type'                 => 'type',
        'wc attribute'         => 'attr_slug',
        'woocommerce attribute'=> 'attr_slug',
        'attribute'            => 'attr_slug',
        'min'                  => 'range_min',
        'minimum'              => 'range_min',
        'max'                  => 'range_max',
        'maximum'              => 'range_max',
        'order'                => 'order',
        'enabled'              => 'enabled',
        'min products'         => 'min_products',
        'min. products'        => 'min_products',
    ];

    while ( ( $line = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
        if ( $headers === null ) {
            $headers = array_map( fn( $h ) => mb_strtolower( trim( $h ) ), $line );
            continue;
        }
        $row = [];
        foreach ( $line as $i => $val ) {
            $raw_key = $headers[ $i ] ?? '';
            $key     = $key_map[ $raw_key ] ?? $raw_key;
            $row[ $key ] = trim( $val );
        }
        if ( ! empty( $row['category'] ) && ! empty( $row['filter_name'] ) ) {
            $rows[] = $row;
        }
    }
    fclose( $handle );

    if ( empty( $rows ) ) {
        wp_send_json_error( 'File contains no data or the header format is invalid' );
    }

    $result = cfc_import_flat_format( $rows, $mode, $dry_run );
    wp_send_json_success( $result );
} );


add_action( 'wp_ajax_cfc_import_xlsx', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $mode     = sanitize_key( wp_unslash( $_POST['mode'] ?? 'skip' ) );
    $dry_run  = ! empty( $_POST['dry_run'] );
    $json_data = wp_unslash( $_POST['data'] ?? '' );

    if ( empty( $json_data ) ) {
        wp_send_json_error( 'No data to import' );
    }

    $payload = json_decode( $json_data, true );
    if ( ! is_array( $payload ) ) {
        wp_send_json_error( 'Invalid data format' );
    }

    $format = $payload['_format'] ?? 'legacy';

    if ( $format === 'flat' ) {
        $result = cfc_import_flat_format( $payload['rows'] ?? [], $mode, $dry_run );
    } else {
        $result = cfc_import_legacy_format( $payload, $mode, $dry_run );
    }

    wp_send_json_success( $result );
} );


function cfc_import_flat_format( array $rows, string $mode, bool $dry_run = false ): array {
    $stats = [
        'imported'   => 0,
        'skipped'    => 0,
        'errors'     => [],
        'categories' => [],
        'dry_run'    => $dry_run,
    ];

    $grouped = [];
    foreach ( $rows as $idx => $row ) {
        $cat_name = trim( $row['category'] ?? '' );
        if ( $cat_name ) {
            $grouped[ $cat_name ][] = [ 'row' => $row, 'line' => $idx + 2 ];
        }
    }

    foreach ( $grouped as $cat_name => $entries ) {
        $term = cfc_find_category_by_name_or_slug( $cat_name );

        if ( ! $term ) {
            $stats['errors'][] = "Category not found: \"{$cat_name}\"";
            continue;
        }

        $existing = cfc_get_own_filters( $term->term_id );
        if ( $existing && $mode === 'skip' ) {
            $stats['skipped']++;
            $stats['categories'][] = [ 'name' => $cat_name, 'status' => 'skipped' ];
            continue;
        }

        $filters  = [];
        $order    = 1;
        foreach ( $entries as $entry ) {
            $filter = cfc_convert_flat_row( $entry['row'], $order );
            if ( $filter === null ) {
                $stats['errors'][] = "Row {$entry['line']}: skipped — filter name missing";
                continue;
            }
            $filters[] = $filter;
            $order++;
        }

        if ( empty( $filters ) ) {
            $stats['errors'][] = "No filters for \"{$cat_name}\"";
            continue;
        }

        if ( ! $dry_run ) {
            if ( $existing ) {
                cfc_log_history( $term->term_id, $existing, 'Import from Excel/CSV' );
            }
            cfc_save_category_filters( $term->term_id, $filters );
        }

        $stats['imported']++;
        $stats['categories'][] = [
            'name'   => $cat_name,
            'status' => $dry_run ? 'would_import' : 'imported',
            'count'  => count( $filters ),
        ];
    }

    return $stats;
}

function cfc_convert_flat_row( array $row, int $order ): ?array {
    $name = trim( $row['filter_name'] ?? '' );
    if ( ! $name ) {
        return null;
    }

    $type      = strtolower( trim( $row['type'] ?? 'checkbox' ) );
    $attr_slug = trim( $row['attr_slug'] ?? '' );

    $allowed = [ 'checkbox', 'range', 'radio', 'toggle', 'color' ];
    if ( ! in_array( $type, $allowed, true ) ) {
        $type_map = [
            'checkboxes' => 'checkbox',
            'slider' => 'range', 'diapason' => 'range',
            'radio buttons' => 'radio',
            'switch' => 'toggle',
        ];
        $type = $type_map[ mb_strtolower( $type ) ] ?? 'checkbox';
    }

    if ( ! $attr_slug ) {
        $attr_slug = cfc_guess_attr_slug( $name );
    }

    return [
        'display_name' => sanitize_text_field( $name ),
        'attr_slug'    => sanitize_key( $attr_slug ),
        'type'         => $type,
        'range_min'    => (float) str_replace( ',', '.', $row['range_min'] ?? '0' ),
        'range_max'    => (float) str_replace( ',', '.', $row['range_max'] ?? '9999' ),
        'order'        => (int) ( $row['order'] ?? $order ),
        'enabled'      => cfc_parse_bool( $row['enabled'] ?? '1' ),
        'min_products' => absint( $row['min_products'] ?? 0 ),
    ];
}

function cfc_guess_attr_slug( string $name ): string {
    $lower = mb_strtolower( $name );
    if ( $lower === 'price' ) {
        return 'price';
    }

    $attr_map = apply_filters( 'cfc_import_attr_map', [
        'Brand' => 'pa_brand', 'Series' => 'pa_series',
        'Blank material' => 'pa_blank_material', 'Action' => 'pa_action',
        'Tip type' => 'pa_tip_type', 'Ring type' => 'pa_ring_type',
        'Handle construction' => 'pa_handle_construction',
        'Handle material' => 'pa_handle_material',
        'Construction' => 'pa_construction', 'Drag type' => 'pa_brake_type',
        'Spool material' => 'pa_spool_material', 'Type' => 'pa_type',
        'Lure type' => 'pa_lure_type', 'Wobbler type' => 'pa_wobbler_type',
        'Bait shape' => 'pa_bait_shape', 'Buoyancy' => 'pa_buoyancy',
        'Gender' => 'pa_gender', 'Material' => 'pa_material',
        'Size' => 'pa_size', 'Color' => 'pa_color',
    ] );

    if ( isset( $attr_map[ $name ] ) ) {
        return $attr_map[ $name ];
    }

    if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
        foreach ( wc_get_attribute_taxonomies() ?: [] as $tax ) {
            if ( mb_strtolower( $tax->attribute_label ) === $lower ) {
                return wc_attribute_taxonomy_name( $tax->attribute_name );
            }
        }
    }

    return 'pa_' . cfc_translit( $name );
}

function cfc_parse_bool( $value ): bool {
    if ( is_bool( $value ) ) {
        return $value;
    }
    $str = mb_strtolower( trim( (string) $value ) );
    return in_array( $str, [ '1', 'yes', 'true', 'on' ], true );
}


function cfc_import_legacy_format( array $sections, string $mode ): array {
    $stats = [ 'imported' => 0, 'skipped' => 0, 'errors' => [], 'categories' => [] ];

    foreach ( $sections as $key => $entries ) {
        if ( $key === '_format' || ! is_array( $entries ) ) {
            continue;
        }
        foreach ( $entries as $entry ) {
            $subcat = sanitize_text_field( $entry['subcategory'] ?? '' );
            if ( ! $subcat ) {
                continue;
            }
            $term = cfc_find_category_by_name_or_slug( $subcat );
            if ( ! $term ) {
                $stats['errors'][] = "Category not found: \"{$subcat}\"";
                continue;
            }
            $existing = cfc_get_own_filters( $term->term_id );
            if ( $existing && $mode === 'skip' ) {
                $stats['skipped']++;
                continue;
            }
            $filters = cfc_convert_legacy_filters( $entry['filters'] ?? [] );
            if ( $existing ) {
                cfc_log_history( $term->term_id, $existing, 'Import from xlsx' );
            }
            cfc_save_category_filters( $term->term_id, $filters );
            $stats['imported']++;
        }
    }
    return $stats;
}

function cfc_convert_legacy_filters( array $xlsx_filters ): array {
    $result = [];
    $order  = 1;
    foreach ( $xlsx_filters as $f ) {
        $name = trim( $f['name'] ?? '' );
        if ( ! $name ) {
            continue;
        }
        $type = $f['type'] ?? 'checkbox';
        $result[] = [
            'display_name' => sanitize_text_field( $name ),
            'attr_slug'    => cfc_guess_attr_slug( $name ),
            'type'         => $type,
            'range_min'    => $type === 'range' ? (float) str_replace( ',', '.', $f['min'] ?? '0' ) : 0,
            'range_max'    => $type === 'range' ? (float) str_replace( ',', '.', $f['max'] ?? '9999' ) : 9999,
            'order'        => $order++,
            'enabled'      => true,
            'min_products' => 0,
        ];
    }
    return $result;
}


function cfc_find_category_by_name_or_slug( string $name ): ?WP_Term {
    $term = get_term_by( 'name', $name, 'product_cat' );
    if ( $term ) {
        return $term;
    }
    $slug = cfc_translit( $name );
    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $term ) {
        return $term;
    }
    $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
    if ( is_array( $terms ) ) {
        foreach ( $terms as $t ) {
            if ( mb_strtolower( $t->name ) === mb_strtolower( $name ) ) {
                return $t;
            }
        }
    }
    return null;
}


add_action( 'wp_ajax_cfc_download_template', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
    $cat_list = [];
    if ( ! is_wp_error( $categories ) ) {
        foreach ( $categories as $cat ) {
            $cat_list[] = [ 'name' => $cat->name, 'slug' => $cat->slug, 'count' => $cat->count ];
        }
    }

    wp_send_json_success( [
        'categories'   => $cat_list,
        'attributes'   => cfc_get_wc_attributes(),
        'filter_types' => [ 'checkbox', 'range', 'radio', 'toggle', 'color' ],
    ] );
} );


add_action( 'wp_ajax_cfc_export_filters', function () {
    check_ajax_referer( 'cfc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }

    $categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ] );
    if ( is_wp_error( $categories ) ) {
        wp_send_json_error( 'Error fetching categories' );
    }

    $rows = [];
    foreach ( $categories as $cat ) {
        $filters = cfc_get_category_filters( $cat->term_id );
        foreach ( $filters as $f ) {
            $rows[] = [
                'category'     => $cat->name,
                'filter_name'  => $f['display_name'] ?? '',
                'type'         => $f['type'] ?? 'checkbox',
                'attr_slug'    => $f['attr_slug'] ?? '',
                'range_min'    => $f['range_min'] ?? 0,
                'range_max'    => $f['range_max'] ?? 9999,
                'order'        => $f['order'] ?? 10,
                'enabled'      => ! empty( $f['enabled'] ) ? 'Yes' : 'No',
                'min_products' => $f['min_products'] ?? 0,
            ];
        }
    }

    wp_send_json_success( [ 'rows' => $rows ] );
} );


function cfc_translit( string $text ): string {
    $lower = mb_strtolower( $text );
    $slug  = str_replace( [ ' ', '_', '/' ], '-', $lower );
    return preg_replace( '/[^a-z0-9-]/', '', $slug );
}
