<?php
/**
 * Plugin Name:  Wishlist Meta
 * Description:  Registers the wishlist_product_ids user meta for the WP REST API.
 *               Once installed, the field is available via GET/PUT /wp-json/wp/v2/users/me.
 * Version:      1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author:       Fishing Store Team
 * License:      GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'init', function (): void {
    register_meta( 'user', 'wishlist_product_ids', [
        // Type is a string (a JSON-serialized array of numbers: "[1,2,3]")
        'type'         => 'string',
        'single'       => true,
        'default'      => '[]',
        'show_in_rest' => [
            'schema' => [ 'type' => 'string' ],
        ],
        // A user can only read/write their own data;
        // unauthenticated third-party requests are rejected.
        'auth_callback' => function ( $allowed, $meta_key, $object_id ): bool {
            $current = get_current_user_id();
            return $current !== 0 && $current === (int) $object_id;
        },
    ] );
} );
