<?php
/**
 * Injects active code blocks (CSS, JS, PHP) into the frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Injector {

    /** Holds active blocks for the lifetime of this PHP process (shared across the 3 hooks). */
    private static $active_blocks_cache = null;

    public static function init() {
        add_action( 'wp_head', array( __CLASS__, 'inject_css' ), 9999 );
        add_action( 'wp_footer', array( __CLASS__, 'inject_js' ), 99 );
        add_action( 'init', array( __CLASS__, 'inject_php' ), 20 );
    }

    /**
     * Returns active blocks, loading once per process (static) and caching across
     * requests via a 5-minute transient.  Reduces 3 DB queries per page load to 1.
     */
    private static function get_blocks() {
        if ( null !== self::$active_blocks_cache ) {
            return self::$active_blocks_cache;
        }

        $cached = get_transient( 'bbse_active_blocks' );
        if ( false !== $cached ) {
            self::$active_blocks_cache = $cached;
            return self::$active_blocks_cache;
        }

        self::$active_blocks_cache = BBSE_Database::get_active_blocks();
        set_transient( 'bbse_active_blocks', self::$active_blocks_cache, 5 * MINUTE_IN_SECONDS );

        return self::$active_blocks_cache;
    }

    public static function inject_css() {
        foreach ( self::get_blocks() as $block ) {
            if ( ! empty( trim( $block['css_code'] ?? '' ) ) ) {
                echo '<style id="bbse-css-' . esc_attr( $block['id'] ) . '">' . "\n";
                echo wp_strip_all_tags( $block['css_code'] ) . "\n";
                echo '</style>' . "\n";
            }
        }
    }

    public static function inject_js() {
        foreach ( self::get_blocks() as $block ) {
            if ( ! empty( trim( $block['js_code'] ?? '' ) ) ) {
                echo '<script id="bbse-js-' . esc_attr( $block['id'] ) . '">' . "\n";
                echo $block['js_code'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</script>' . "\n";
            }
        }
    }

    public static function inject_php() {
        foreach ( self::get_blocks() as $block ) {
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
