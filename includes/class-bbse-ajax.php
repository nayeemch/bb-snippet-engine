<?php
/**
 * AJAX handlers for BBSE
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Ajax {

    public static function init() {
        add_action( 'wp_ajax_bbse_toggle_block', array( __CLASS__, 'toggle_block' ) );
        add_action( 'wp_ajax_bbse_save_block', array( __CLASS__, 'save_block' ) );
        add_action( 'wp_ajax_bbse_delete_block', array( __CLASS__, 'delete_block' ) );
        add_action( 'wp_ajax_bbse_delete_all_blocks', array( __CLASS__, 'delete_all_blocks' ) );
        add_action( 'wp_ajax_bbse_create_block', array( __CLASS__, 'create_block' ) );
        add_action( 'wp_ajax_bbse_get_block', array( __CLASS__, 'get_block' ) );
        add_action( 'wp_ajax_bbse_sync_remote_blocks', array( __CLASS__, 'sync_remote_blocks' ) );
        add_action( 'wp_ajax_bbse_get_blocks_page', array( __CLASS__, 'get_blocks_page' ) );
    }

    public static function sync_remote_blocks() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $summary = BBSE_Remote_Sync::sync_from_gist();
        if ( is_wp_error( $summary ) ) {
            wp_send_json_error( array( 'message' => $summary->get_error_message() ) );
        }

        $message = sprintf(
            /* translators: 1: created count, 2: updated count, 3: skipped count */
            __( 'Sync complete. Created: %1$d, Updated: %2$d, Skipped: %3$d.', 'bbse' ),
            (int) $summary['created'],
            (int) $summary['updated'],
            (int) $summary['skipped']
        );

        wp_send_json_success(
            array(
                'message' => $message,
                'summary' => $summary,
            )
        );
    }

    public static function get_block() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $id = isset( $_GET['block_id'] ) ? absint( $_GET['block_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid block ID', 'bbse' ) ) );
        }

        $block = BBSE_Database::get_block( $id );
        if ( ! $block ) {
            wp_send_json_error( array( 'message' => __( 'Block not found', 'bbse' ) ) );
        }

        wp_send_json_success( $block );
    }

    public static function toggle_block() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid block ID', 'bbse' ) ) );
        }

        $block = BBSE_Database::get_block( $id );
        if ( ! $block ) {
            wp_send_json_error( array( 'message' => __( 'Block not found', 'bbse' ) ) );
        }

        $is_activating = empty( $block['is_active'] );

        if ( $is_activating ) {
            $validation = self::validate_block_code( $block );
            if ( is_wp_error( $validation ) ) {
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: 1: block name, 2: error message */
                        __( 'Block "%1$s" could not be activated: %2$s', 'bbse' ),
                        esc_html( $block['name'] ),
                        $validation->get_error_message()
                    ),
                ) );
            }
        }

        $block_name = $block['name'];
        $new_status = BBSE_Database::toggle_block( $id, $block );
        $message = $new_status
            ? sprintf( __( 'Block "%s" activated successfully.', 'bbse' ), esc_html( $block_name ) )
            : sprintf( __( 'Block "%s" deactivated successfully.', 'bbse' ), esc_html( $block_name ) );

        wp_send_json_success( array(
            'is_active' => (bool) $new_status,
            'message'   => $message,
        ) );
    }

    private static function normalize_code( $code ) {
        // Replace Unicode characters that web pages substitute for ASCII equivalents
        // (non-breaking spaces, smart quotes, en/em dashes, angle quotation marks).
        $map = array(
            "\u{00A0}" => ' ',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{2032}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '--',
            "\u{2039}" => '<',
            "\u{203A}" => '>',
        );
        return str_replace( array_keys( $map ), array_values( $map ), $code );
    }

    /**
     * Validates block code (PHP) before activation to prevent fatal errors.
     *
     * @param array $block Block data with css_code, js_code, php_code.
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    private static function validate_block_code( $block ) {
        $php_code = trim( $block['php_code'] ?? '' );
        if ( empty( $php_code ) ) {
            return true;
        }

        $code = preg_replace( '/^<\?php\s*/i', '', $php_code );
        $code = preg_replace( '/\s*\?>$/s', '', $code );
        if ( '' === $code ) {
            return true;
        }

        // TOKEN_PARSE checks syntax without executing — avoids false "Cannot redeclare" errors
        // that eval() triggers when functions defined in this block are already loaded.
        try {
            token_get_all( '<?php ' . $code, TOKEN_PARSE );
        } catch ( ParseError $e ) {
            return new WP_Error( 'php_error', $e->getMessage() );
        }

        return true;
    }

    public static function save_block() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid block ID', 'bbse' ) ) );
        }

        $data = array(
            'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'css_code' => isset( $_POST['css_code'] ) ? self::normalize_code( wp_unslash( $_POST['css_code'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'js_code'  => isset( $_POST['js_code'] ) ? self::normalize_code( wp_unslash( $_POST['js_code'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'php_code' => isset( $_POST['php_code'] ) ? self::normalize_code( wp_unslash( $_POST['php_code'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        );

        $validation = self::validate_block_code( $data );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: %s: error message */
                    __( 'Cannot save: PHP code has an error. %s', 'bbse' ),
                    $validation->get_error_message()
                ),
            ) );
        }

        BBSE_Database::update_block( $id, $data );
        wp_send_json_success( array( 'message' => __( 'Block saved successfully.', 'bbse' ) ) );
    }

    public static function delete_block() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid block ID', 'bbse' ) ) );
        }

        BBSE_Database::delete_block( $id );
        wp_send_json_success( array( 'message' => __( 'Block deleted successfully.', 'bbse' ) ) );
    }

    public static function delete_all_blocks() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        BBSE_Database::delete_all_blocks();
        wp_send_json_success( array( 'message' => __( 'All blocks deleted.', 'bbse' ) ) );
    }

    public static function get_blocks_page() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $args = array(
            'page'     => isset( $_GET['page_num'] ) ? absint( $_GET['page_num'] ) : 1,
            'per_page' => isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 12,
            'search'   => isset( $_GET['search'] )   ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
            'orderby'  => isset( $_GET['orderby'] )  ? sanitize_key( $_GET['orderby'] ) : 'id',
            'order'    => isset( $_GET['order'] )     ? sanitize_key( $_GET['order'] )   : 'DESC',
        );

        $result = BBSE_Database::get_blocks_for_listing( $args );

        ob_start();
        foreach ( $result['items'] as $block ) {
            BBSE_Admin::render_block_card( $block );
        }
        $html = ob_get_clean();

        wp_send_json_success( array(
            'html'         => $html,
            'total'        => $result['total'],
            'active_total' => BBSE_Database::count_active_blocks(),
            'page'         => $args['page'],
            'per_page'     => $args['per_page'],
            'total_pages'  => (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ),
        ) );
    }

    public static function create_block() {
        check_ajax_referer( 'bbse_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'bbse' ) ) );
        }

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : __( 'New Block', 'bbse' );
        $id   = BBSE_Database::insert_block( array( 'name' => $name ) );

        if ( $id ) {
            wp_send_json_success( array(
                'id'      => $id,
                'message' => __( 'Block created successfully.', 'bbse' ),
            ) );
        }

        wp_send_json_error( array( 'message' => __( 'Failed to create block.', 'bbse' ) ) );
    }
}
