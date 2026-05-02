<?php
/**
 * Database handler for BBSE code blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Database {

    const TABLE_NAME = 'bbse_blocks';
    const OPTION_KEY = 'bbse_db_version';

    public static function init() {
        self::maybe_upgrade_schema();
    }

    public static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            css_code longtext,
            js_code longtext,
            php_code longtext,
            is_active tinyint(1) DEFAULT 0,
            sort_order int(11) DEFAULT 0,
            source_type varchar(20) DEFAULT 'local',
            remote_id varchar(191) DEFAULT NULL,
            remote_version varchar(100) DEFAULT NULL,
            is_locked tinyint(1) DEFAULT 0,
            last_synced_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY remote_id_idx (remote_id),
            KEY source_type_idx (source_type)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::OPTION_KEY, BBSE_VERSION );
    }

    private static function maybe_upgrade_schema() {
        global $wpdb;

        // Migrate from the old AoBB table name (addon_bb_blocks → bbse_blocks).
        $old_table = $wpdb->prefix . 'addon_bb_blocks';
        $new_table = $wpdb->prefix . self::TABLE_NAME;
        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $old_exists && ! $new_exists ) {
            $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB
            $old_version = get_option( 'addon_bb_db_version', '' );
            if ( $old_version ) {
                update_option( self::OPTION_KEY, $old_version );
                delete_option( 'addon_bb_db_version' );
            }
        }

        $installed_version = get_option( self::OPTION_KEY, '' );
        if ( BBSE_VERSION !== $installed_version ) {
            self::create_tables();
            self::maybe_add_is_active_index();
        }
    }

    /**
     * Adds an index on is_active if it does not already exist.
     * Uses a version-gated ALTER TABLE rather than dbDelta (whose index diffing is unreliable).
     */
    private static function maybe_add_is_active_index() {
        global $wpdb;
        $table = self::get_table_name();

        $index_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name   = %s
                   AND index_name   = 'is_active_idx'",
                $table
            )
        );

        if ( ! $index_exists ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `is_active_idx` (`is_active`)" ); // phpcs:ignore WordPress.DB
        }
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Deletes the active-blocks transient so the injector picks up changes on the next request.
     */
    public static function flush_active_blocks_cache() {
        delete_transient( 'bbse_active_blocks' );
    }

    /**
     * Returns the total number of blocks, optionally filtered by a name search term.
     */
    public static function count_blocks( $search = '' ) {
        global $wpdb;
        $table = self::get_table_name();
        if ( '' !== $search ) {
            return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s",
                    '%' . $wpdb->esc_like( $search ) . '%'
                )
            );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Returns the number of currently active blocks.
     */
    public static function count_active_blocks() {
        global $wpdb;
        $table = self::get_table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Returns a paginated, filtered, sorted list of blocks for the admin listing UI.
     * Only selects metadata columns — never fetches longtext code content.
     *
     * @param array $args {
     *   int    $page     1-based page number.  Default 1.
     *   int    $per_page Rows per page.         Default 12.
     *   string $search   Name search term.      Default ''.
     *   string $orderby  id|name|is_active.     Default 'id'.
     *   string $order    ASC|DESC.              Default 'DESC'.
     * }
     * @return array { items: array[], total: int }
     */
    public static function get_blocks_for_listing( $args = array() ) {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = array(
            'page'     => 1,
            'per_page' => 12,
            'search'   => '',
            'orderby'  => 'id',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $page     = max( 1, (int) $args['page'] );
        $per_page = max( 1, min( 100, (int) $args['per_page'] ) );
        $offset   = ( $page - 1 ) * $per_page;
        $search   = sanitize_text_field( $args['search'] );

        $allowed_orderby = array( 'id', 'name', 'is_active' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
        $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        // Secondary sort by name for stable ordering when primary values are equal.
        $order_clause = ( 'is_active' === $orderby )
            ? "ORDER BY is_active {$order}, name ASC"
            : "ORDER BY {$orderby} {$order}";

        // Columns: metadata only + computed boolean presence flags.
        $select = "SELECT id, name, is_active, source_type, is_locked,
            CASE WHEN LENGTH(TRIM(css_code)) > 0 THEN 1 ELSE 0 END AS has_css,
            CASE WHEN LENGTH(TRIM(js_code))  > 0 THEN 1 ELSE 0 END AS has_js,
            CASE WHEN LENGTH(TRIM(php_code)) > 0 THEN 1 ELSE 0 END AS has_php,
            created_at, updated_at
        FROM {$table}";

        if ( '' !== $search ) {
            $like  = '%' . $wpdb->esc_like( $search ) . '%';
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare( "{$select} WHERE name LIKE %s {$order_clause} LIMIT %d OFFSET %d", $like, $per_page, $offset ),
                ARRAY_A
            );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $items = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare( "{$select} {$order_clause} LIMIT %d OFFSET %d", $per_page, $offset ),
                ARRAY_A
            );
        }

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    public static function get_all_blocks() {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    public static function get_block( $id ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
    }

    public static function get_active_blocks() {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_results( "SELECT * FROM $table WHERE is_active = 1 ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    public static function toggle_block( $id, $block = null ) {
        global $wpdb;
        $table = self::get_table_name();
        if ( null === $block ) {
            $block = self::get_block( $id );
        }
        if ( ! $block ) {
            return false;
        }
        $new_status = $block['is_active'] ? 0 : 1;
        $wpdb->update( $table, array( 'is_active' => $new_status ), array( 'id' => $id ) );
        self::flush_active_blocks_cache();
        return $new_status;
    }

    public static function insert_block( $data ) {
        global $wpdb;
        $table = self::get_table_name();
        $defaults = array(
            'name'           => 'New Block',
            'css_code'       => '',
            'js_code'        => '',
            'php_code'       => '',
            'is_active'      => 0,
            'sort_order'     => 0,
            'source_type'    => 'local',
            'remote_id'      => null,
            'remote_version' => null,
            'is_locked'      => 0,
            'last_synced_at' => null,
        );
        $data = wp_parse_args( $data, $defaults );
        $wpdb->insert( $table, $data );
        return $wpdb->insert_id;
    }

    public static function get_block_by_remote_id( $remote_id ) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE remote_id = %s AND source_type = 'remote' LIMIT 1", $remote_id ),
            ARRAY_A
        );
    }

    public static function upsert_remote_block( $block, $default_active = false ) {
        global $wpdb;
        $table = self::get_table_name();
        $existing = self::get_block_by_remote_id( $block['remote_id'] );
        $timestamp = current_time( 'mysql' );
        $payload = array(
            'name'           => $block['name'],
            'css_code'       => $block['css_code'],
            'js_code'        => $block['js_code'],
            'php_code'       => $block['php_code'],
            'source_type'    => 'remote',
            'remote_id'      => $block['remote_id'],
            'remote_version' => $block['remote_version'],
            'is_locked'      => 0,
            'last_synced_at' => $timestamp,
        );

        if ( $existing ) {
            $wpdb->update( $table, $payload, array( 'id' => $existing['id'] ) );
            self::flush_active_blocks_cache();
            return array( 'action' => 'updated', 'id' => (int) $existing['id'] );
        }

        $payload['is_active'] = $default_active ? 1 : 0;
        $wpdb->insert( $table, $payload );
        self::flush_active_blocks_cache();
        return array( 'action' => 'created', 'id' => (int) $wpdb->insert_id );
    }

    public static function deactivate_missing_remote_blocks( $remote_ids ) {
        global $wpdb;
        $table = self::get_table_name();
        $remote_ids = array_values( array_filter( array_map( 'sanitize_text_field', (array) $remote_ids ) ) );
        if ( empty( $remote_ids ) ) {
            $wpdb->query( "UPDATE $table SET is_active = 0 WHERE source_type = 'remote'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            self::flush_active_blocks_cache();
            return;
        }
        $placeholders = implode( ',', array_fill( 0, count( $remote_ids ), '%s' ) );
        $sql = $wpdb->prepare(
            "UPDATE $table SET is_active = 0 WHERE source_type = 'remote' AND remote_id NOT IN ($placeholders)",
            $remote_ids
        );
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        self::flush_active_blocks_cache();
    }

    public static function update_block( $id, $data ) {
        global $wpdb;
        $table  = self::get_table_name();
        $result = $wpdb->update( $table, $data, array( 'id' => $id ) );
        self::flush_active_blocks_cache();
        return $result;
    }

    public static function delete_block( $id ) {
        global $wpdb;
        $table  = self::get_table_name();
        $result = $wpdb->delete( $table, array( 'id' => $id ) );
        self::flush_active_blocks_cache();
        return $result;
    }

    public static function delete_all_blocks() {
        global $wpdb;
        $table  = self::get_table_name();
        $result = $wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        self::flush_active_blocks_cache();
        return $result;
    }
}
