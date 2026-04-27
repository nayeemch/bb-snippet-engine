<?php
/**
 * Remote predefined block sync service.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Remote_Sync {

    const OPTION_LAST_SYNC_SUMMARY = 'bbse_last_sync_summary';

    public static function get_gist_url() {
        if ( defined( 'BBSE_GIST_URL' ) && ! empty( BBSE_GIST_URL ) ) {
            return (string) BBSE_GIST_URL;
        }
        return '';
    }

    public static function get_last_summary() {
        $summary = get_option( self::OPTION_LAST_SYNC_SUMMARY, array() );
        return is_array( $summary ) ? $summary : array();
    }

    public static function sync_from_gist( $url = '' ) {
        $url = $url ? $url : self::get_gist_url();
        if ( empty( $url ) ) {
            return new WP_Error( 'missing_url', __( 'Please provide a GitHub Gist URL first.', 'bbse' ) );
        }

        $payload = self::fetch_payload( $url );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $blocks = isset( $payload['blocks'] ) && is_array( $payload['blocks'] ) ? $payload['blocks'] : array();
        $summary = array(
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'deactivated' => 0,
            'errors' => array(),
            'synced_at' => current_time( 'mysql' ),
        );

        $remote_ids = array();
        foreach ( $blocks as $item ) {
            $normalized = self::normalize_block( $item );
            if ( is_wp_error( $normalized ) ) {
                $summary['errors'][] = $normalized->get_error_message();
                $summary['skipped']++;
                continue;
            }

            $remote_ids[] = $normalized['remote_id'];
            $result = BBSE_Database::upsert_remote_block( $normalized, ! empty( $normalized['default_active'] ) );
            if ( 'created' === $result['action'] ) {
                $summary['created']++;
            } else {
                $summary['updated']++;
            }
        }

        BBSE_Database::deactivate_missing_remote_blocks( $remote_ids );
        update_option( self::OPTION_LAST_SYNC_SUMMARY, $summary );

        return $summary;
    }

    private static function fetch_payload( $url ) {
        $request_url = $url;
        if ( preg_match( '#gist\.github\.com/[^/]+/([a-f0-9]+)#i', $url, $match ) ) {
            $request_url = 'https://api.github.com/gists/' . $match[1];
        }

        $response = wp_remote_get(
            $request_url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'BBSE-RemoteSync',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( 200 !== $code ) {
            return new WP_Error( 'http_error', sprintf( __( 'Remote fetch failed with HTTP %d.', 'bbse' ), $code ) );
        }

        $decoded = json_decode( $body, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new WP_Error( 'json_invalid', __( 'Remote response is not valid JSON.', 'bbse' ) );
        }

        // Gist API payload.
        if ( isset( $decoded['files'] ) && is_array( $decoded['files'] ) ) {
            foreach ( $decoded['files'] as $file ) {
                if ( isset( $file['content'] ) ) {
                    $json = json_decode( (string) $file['content'], true );
                    if ( JSON_ERROR_NONE === json_last_error() && isset( $json['blocks'] ) ) {
                        return $json;
                    }
                }
            }
            return new WP_Error( 'gist_missing_json', __( 'No valid JSON blocks file found in this Gist.', 'bbse' ) );
        }

        if ( isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
            return $decoded;
        }

        return new WP_Error( 'schema_invalid', __( 'JSON must contain a top-level "blocks" array.', 'bbse' ) );
    }

    private static function normalize_block( $item ) {
        if ( ! is_array( $item ) ) {
            return new WP_Error( 'invalid_block', __( 'Invalid block payload encountered.', 'bbse' ) );
        }

        $remote_id = isset( $item['remote_id'] ) ? sanitize_text_field( $item['remote_id'] ) : '';
        if ( '' === $remote_id ) {
            return new WP_Error( 'missing_remote_id', __( 'Each remote block must include remote_id.', 'bbse' ) );
        }

        return array(
            'remote_id' => $remote_id,
            'remote_version' => isset( $item['version'] ) ? sanitize_text_field( (string) $item['version'] ) : '',
            'name' => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : $remote_id,
            'css_code' => isset( $item['css_code'] ) ? (string) $item['css_code'] : '',
            'js_code' => isset( $item['js_code'] ) ? (string) $item['js_code'] : '',
            'php_code' => isset( $item['php_code'] ) ? (string) $item['php_code'] : '',
            'default_active' => ! empty( $item['default_active'] ),
        );
    }
}

