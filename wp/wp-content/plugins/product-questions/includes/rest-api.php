<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', function (): void {

    // GET /fishing/v1/questions?product_id=123&page=1&per_page=10
    register_rest_route( 'fishing/v1', '/questions', [
        'methods'             => 'GET',
        'callback'            => 'pq_rest_get_questions',
        'permission_callback' => '__return_true',
        'args'                => [
            'product_id' => [ 'required' => true,  'sanitize_callback' => 'absint' ],
            'page'       => [ 'required' => false, 'sanitize_callback' => 'absint', 'default' => 1 ],
            'per_page'   => [ 'required' => false, 'sanitize_callback' => 'absint', 'default' => 10 ],
        ],
    ] );

    // POST /fishing/v1/questions — guest or authenticated user
    register_rest_route( 'fishing/v1', '/questions', [
        'methods'             => 'POST',
        'callback'            => 'pq_rest_submit_question',
        'permission_callback' => '__return_true',
    ] );

    // PUT /fishing/v1/questions/{id}/answer — manager only
    register_rest_route( 'fishing/v1', '/questions/(?P<id>\d+)/answer', [
        'methods'             => 'PUT',
        'callback'            => 'pq_rest_answer_question',
        'permission_callback' => function (): bool {
            return current_user_can( 'manage_woocommerce' );
        },
        'args' => [
            'id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
        ],
    ] );
} );

// ─── GET ─────────────────────────────────────────────────────────────────────

function pq_rest_get_questions( WP_REST_Request $req ): WP_REST_Response {
    global $wpdb;

    $product_id = (int) $req->get_param( 'product_id' );
    $page       = max( 1, (int) $req->get_param( 'page' ) );
    $per_page   = min( 50, max( 1, (int) $req->get_param( 'per_page' ) ) );
    $offset     = ( $page - 1 ) * $per_page;

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM " . PQ_TABLE . " WHERE product_id = %d AND status = 'answered'",
        $product_id
    ) );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, user_name, question, answer, created_at
         FROM " . PQ_TABLE . "
         WHERE product_id = %d AND status = 'answered'
         ORDER BY created_at DESC
         LIMIT %d OFFSET %d",
        $product_id, $per_page, $offset
    ), ARRAY_A );

    $questions = array_map( fn( $r ) => [
        'id'          => (int) $r['id'],
        'userName'    => $r['user_name'],
        'text'        => $r['question'],
        'storeAnswer' => $r['answer'] ?? '',
    ], $rows ?: [] );

    return new WP_REST_Response( [
        'questions' => $questions,
        'total'     => $total,
        'pages'     => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
    ], 200 );
}

// ─── POST ────────────────────────────────────────────────────────────────────

function pq_rest_submit_question( WP_REST_Request $req ): WP_REST_Response {
    global $wpdb;

    $body       = $req->get_json_params() ?: [];
    $product_id = absint( $body['product_id'] ?? 0 );
    $text       = sanitize_textarea_field( $body['question_text'] ?? '' );
    $user_name  = sanitize_text_field( $body['user_name'] ?? '' );

    if ( ! $product_id || ! $text ) {
        return new WP_REST_Response( [ 'error' => 'product_id and question_text are required' ], 400 );
    }

    // If the user is authenticated — take their name from the profile
    $user_id = get_current_user_id();
    if ( $user_id ) {
        $wp_user   = get_userdata( $user_id );
        $user_name = $wp_user ? trim( $wp_user->display_name ?: $wp_user->user_login ) : $user_name;
    }

    if ( ! $user_name ) {
        $user_name = 'Guest';
    }

    // Check whether the product exists
    if ( get_post_type( $product_id ) !== 'product' ) {
        return new WP_REST_Response( [ 'error' => 'Product not found' ], 404 );
    }

    $inserted = $wpdb->insert( PQ_TABLE, [
        'product_id' => $product_id,
        'user_id'    => $user_id,
        'user_name'  => $user_name,
        'question'   => $text,
        'status'     => 'pending',
        'created_at' => current_time( 'mysql' ),
    ] );

    if ( ! $inserted ) {
        return new WP_REST_Response( [ 'error' => 'Failed to save' ], 500 );
    }

    return new WP_REST_Response( [ 'id' => $wpdb->insert_id, 'status' => 'pending' ], 201 );
}

// ─── PUT /{id}/answer ────────────────────────────────────────────────────────

function pq_rest_answer_question( WP_REST_Request $req ): WP_REST_Response {
    global $wpdb;

    $id     = (int) $req->get_param( 'id' );
    $body   = $req->get_json_params() ?: [];
    $answer = sanitize_textarea_field( $body['answer'] ?? '' );

    if ( ! $answer ) {
        return new WP_REST_Response( [ 'error' => 'answer is required' ], 400 );
    }

    $updated = $wpdb->update(
        PQ_TABLE,
        [
            'answer'      => $answer,
            'status'      => 'answered',
            'answered_at' => current_time( 'mysql' ),
        ],
        [ 'id' => $id ],
    );

    if ( $updated === false ) {
        return new WP_REST_Response( [ 'error' => 'Record not found or DB error' ], 404 );
    }

    return new WP_REST_Response( [ 'id' => $id, 'status' => 'answered' ], 200 );
}
