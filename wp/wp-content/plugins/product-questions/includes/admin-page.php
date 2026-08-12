<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function pq_render_admin_page(): void {
    global $wpdb;

    // Handle answering a question
    if (
        isset( $_POST['pq_answer_nonce'], $_POST['pq_id'], $_POST['pq_answer'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pq_answer_nonce'] ) ), 'pq_answer' ) &&
        current_user_can( 'manage_woocommerce' )
    ) {
        $id     = absint( $_POST['pq_id'] );
        $answer = sanitize_textarea_field( wp_unslash( $_POST['pq_answer'] ) );

        if ( $answer ) {
            $wpdb->update( PQ_TABLE, [
                'answer'      => $answer,
                'status'      => 'answered',
                'answered_at' => current_time( 'mysql' ),
            ], [ 'id' => $id ] );
            echo '<div class="notice notice-success is-dismissible"><p>Answer saved.</p></div>';
        }
    }

    // Handle rejection
    if (
        isset( $_POST['pq_reject_nonce'], $_POST['pq_reject_id'] ) &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pq_reject_nonce'] ) ), 'pq_reject' ) &&
        current_user_can( 'manage_woocommerce' )
    ) {
        $id = absint( $_POST['pq_reject_id'] );
        $wpdb->update( PQ_TABLE, [ 'status' => 'rejected' ], [ 'id' => $id ] );
        echo '<div class="notice notice-warning is-dismissible"><p>Question rejected.</p></div>';
    }

    $tab = isset( $_GET['pq_tab'] ) ? sanitize_key( $_GET['pq_tab'] ) : 'pending';

    $allowed_statuses = [ 'pending', 'answered', 'rejected' ];
    if ( ! in_array( $tab, $allowed_statuses, true ) ) {
        $tab = 'pending';
    }

    $counts = [];
    foreach ( $allowed_statuses as $s ) {
        $counts[ $s ] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . PQ_TABLE . " WHERE status = %s", $s
        ) );
    }

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT q.*, p.post_title as product_name
         FROM " . PQ_TABLE . " q
         LEFT JOIN {$wpdb->posts} p ON p.ID = q.product_id
         WHERE q.status = %s
         ORDER BY q.created_at DESC
         LIMIT 50",
        $tab
    ), ARRAY_A );

    $admin_url = admin_url( 'admin.php?page=product-questions' );
    ?>
    <div class="wrap">
        <h1>Product Questions</h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:16px">
            <?php foreach ( [ 'pending' => 'Awaiting answer', 'answered' => 'Answered', 'rejected' => 'Rejected' ] as $s => $label ) : ?>
                <a href="<?= esc_url( $admin_url . '&pq_tab=' . $s ) ?>"
                   class="nav-tab <?= $tab === $s ? 'nav-tab-active' : '' ?>">
                    <?= esc_html( $label ) ?>
                    <span class="awaiting-mod count-<?= esc_attr( $counts[ $s ] ) ?>">
                        <span class="pending-count"><?= esc_html( $counts[ $s ] ) ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ( empty( $rows ) ) : ?>
            <p>No questions.</p>
        <?php else : ?>
            <?php foreach ( $rows as $row ) : ?>
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;margin-bottom:16px">
                    <p style="margin:0 0 4px">
                        <strong><?= esc_html( $row['user_name'] ) ?></strong>
                        &nbsp;→&nbsp;
                        <a href="<?= esc_url( get_permalink( (int) $row['product_id'] ) ) ?>" target="_blank">
                            <?= esc_html( $row['product_name'] ?: 'Product #' . $row['product_id'] ) ?>
                        </a>
                        <span style="color:#666;font-size:12px;margin-left:8px">
                            <?= esc_html( wp_date( 'M j, Y H:i', strtotime( $row['created_at'] ) ) ) ?>
                        </span>
                    </p>
                    <p style="margin:8px 0;font-style:italic">"<?= esc_html( $row['question'] ) ?>"</p>

                    <?php if ( $row['answer'] ) : ?>
                        <p style="margin:8px 0;padding:8px;background:#f0f8e8;border-left:3px solid #46b450">
                            <strong>Answer:</strong> <?= esc_html( $row['answer'] ) ?>
                        </p>
                    <?php endif; ?>

                    <?php if ( $tab === 'pending' ) : ?>
                        <form method="post" style="margin-top:8px">
                            <?php wp_nonce_field( 'pq_answer', 'pq_answer_nonce' ); ?>
                            <input type="hidden" name="pq_id" value="<?= esc_attr( $row['id'] ) ?>">
                            <textarea name="pq_answer" rows="3" style="width:100%;margin-bottom:8px"
                                      placeholder="Enter an answer..."></textarea>
                            <button type="submit" class="button button-primary">Answer and publish</button>
                            &nbsp;
                            <button type="submit" form="reject-<?= esc_attr( $row['id'] ) ?>"
                                    class="button">Reject</button>
                        </form>
                        <form method="post" id="reject-<?= esc_attr( $row['id'] ) ?>"
                              style="display:none">
                            <?php wp_nonce_field( 'pq_reject', 'pq_reject_nonce' ); ?>
                            <input type="hidden" name="pq_reject_id" value="<?= esc_attr( $row['id'] ) ?>">
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
