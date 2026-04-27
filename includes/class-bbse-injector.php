<?php
/**
 * Injects active code blocks (CSS, JS, PHP) into the frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Injector {

    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'inject_css' ), 9999 );
        add_action( 'wp_footer', array( __CLASS__, 'inject_js' ), 99 );
        add_action( 'init', array( __CLASS__, 'inject_php' ), 20 );
    }

    public static function inject_css() {
        $blocks = BBSE_Database::get_active_blocks();
        foreach ( $blocks as $block ) {
            if ( ! empty( trim( $block['css_code'] ?? '' ) ) ) {
                echo '<style id="bbse-css-' . esc_attr( $block['id'] ) . '">' . "\n";
                echo wp_strip_all_tags( $block['css_code'] ) . "\n";
                echo '</style>' . "\n";
            }
        }
    }

    public static function inject_js() {
        $blocks = BBSE_Database::get_active_blocks();
        foreach ( $blocks as $block ) {
            if ( ! empty( trim( $block['js_code'] ?? '' ) ) ) {
                echo '<script id="bbse-js-' . esc_attr( $block['id'] ) . '">' . "\n";
                echo $block['js_code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</script>' . "\n";
            }
        }
    }

    public static function inject_php() {
        $blocks = BBSE_Database::get_active_blocks();
        foreach ( $blocks as $block ) {
            if ( empty( trim( $block['php_code'] ?? '' ) ) ) {
                continue;
            }
            $code = trim( $block['php_code'] );
            $code = preg_replace( '/^<\?php\s*/', '', $code );
            $code = preg_replace( '/\s*\?>$/s', '', $code );
            if ( '' === $code ) {
                continue;
            }
            $block_id = $block['id'];
            try {
                eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
            } catch ( Throwable $e ) {
                if ( current_user_can( 'manage_options' ) ) {
                    error_log( 'BBSE PHP Block ' . $block_id . ' error: ' . $e->getMessage() );
                }
            }
        }
    }
}
