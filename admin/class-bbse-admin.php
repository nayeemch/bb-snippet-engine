<?php
/**
 * Admin UI for BBSE - Material Design card style
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BBSE_Admin {

    const PAGE_SLUG = 'bbse-code-blocks';
    const CAPABILITY = 'manage_options';

    public static function init() {
        // hook at 20 so BuddyBoss has had a chance to register its own menu
        // (which usually runs at priority 10).  That way we can detect the
        // parent slug and add ourselves as a submenu item.
        add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 200 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    public static function add_menu_page() {
        // BuddyBoss defines its main menu slug as "buddyboss-platform".  We
        // register our admin UI as a child of that menu so it appears in the
        // BuddyBoss section instead of as a top‑level item.  If BuddyBoss
        // isn't active we fall back to creating a standalone page.
        $parent = 'buddyboss-platform';

        if ( ! isset( $GLOBALS['menu'] ) || ! isset( $GLOBALS['submenu'][ $parent ] ) ) {
            // BuddyBoss not loaded yet – just create a top level page.
            add_menu_page(
                __( 'BBSE', 'bbse' ),
                __( 'BBSE', 'bbse' ),
                self::CAPABILITY,
                self::PAGE_SLUG,
                array( __CLASS__, 'render_page' ),
                'dashicons-image-filter',
                80
            );
        } else {
            add_submenu_page(
                $parent,
                __( 'BBSE', 'bbse' ),
                __( 'BBSE', 'bbse' ),
                self::CAPABILITY,
                self::PAGE_SLUG,
                array( __CLASS__, 'render_page' )
            );
        }
    }

    public static function enqueue_assets( $hook ) {
        $valid_hooks = array(
            'toplevel_page_' . self::PAGE_SLUG,
            'buddyboss-platform_page_' . self::PAGE_SLUG,
            'buddyboss_page_' . self::PAGE_SLUG,
        );
        if ( ! in_array( $hook, $valid_hooks, true ) ) {
            return;
        }

        // Enqueue WordPress CodeMirror for CSS, JS, PHP modes
        $css_settings  = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
        $js_settings   = wp_enqueue_code_editor( array( 'type' => 'application/javascript' ) );
        $php_settings  = wp_enqueue_code_editor( array( 'type' => 'application/x-httpd-php' ) );

        $code_editor_available = ( false !== $css_settings && false !== $js_settings && false !== $php_settings );

        wp_enqueue_style(
            'bbse-admin',
            BBSE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BBSE_VERSION
        );

        wp_enqueue_script(
            'canvas-confetti',
            'https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js',
            array(),
            '1.9.2',
            true
        );

        wp_enqueue_script(
            'bbse-admin',
            BBSE_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'code-editor', 'canvas-confetti' ),
            BBSE_VERSION,
            true
        );

        $per_page    = 12;
        $total_count  = BBSE_Database::count_blocks();
        $active_count = BBSE_Database::count_active_blocks();

        wp_localize_script( 'bbse-admin', 'bbse', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'bbse_nonce' ),
            'pagination' => array(
                'perPage'     => $per_page,
                'totalBlocks' => $total_count,
                'totalPages'  => (int) ceil( $total_count / $per_page ),
                'activeCount' => $active_count,
            ),
            'strings'    => array(
                'activate'   => __( 'Activated', 'bbse' ),
                'deactivate' => __( 'Deactivated', 'bbse' ),
                'saved'      => __( 'Saved', 'bbse' ),
                'deleted'    => __( 'Deleted', 'bbse' ),
                'error'      => __( 'Something went wrong.', 'bbse' ),
                'confirmDelete'    => __( 'Are you sure you want to delete this block?', 'bbse' ),
                'confirmDeleteAll' => __( 'Delete ALL blocks? This cannot be undone.', 'bbse' ),
                'newBlock'   => __( 'New Block', 'bbse' ),
                'syncing'    => __( 'Syncing...', 'bbse' ),
                'addBlock'   => __( 'Add New Block', 'bbse' ),
                'editBlock'  => __( 'Edit Code Block', 'bbse' ),
            ),
            'codeEditor' => array(
                'available' => $code_editor_available,
                'settings'  => array(
                    'css'  => $code_editor_available && $css_settings ? self::get_editor_settings( $css_settings, 'text/css' ) : null,
                    'js'   => $code_editor_available && $js_settings ? self::get_editor_settings( $js_settings, 'application/javascript' ) : null,
                    'php'  => $code_editor_available && $php_settings ? self::get_editor_settings( $php_settings, 'application/x-httpd-php' ) : null,
                ),
            ),
        ) );
    }

    public static function render_page() {
        $listing      = BBSE_Database::get_blocks_for_listing( array(
            'page'     => 1,
            'per_page' => 12,
            'orderby'  => 'is_active',
            'order'    => 'DESC',
        ) );
        $blocks       = $listing['items'];
        $total_count  = $listing['total'];
        $active_count = BBSE_Database::count_active_blocks();
        ?>
        <div class="wrap bbse-wrap">
            <div class="bbse-header">
                <div class="bbse-header-main">
                    <h1 class="bbse-title">
                        <span class="bbse-icon"><?php echo esc_html__( 'BBSE - BuddyBoss Snippet Engine', 'bbse' ); ?></span>
                    </h1>
                    <p class="bbse-subtitle"><?php esc_html_e( 'Inject CSS, JS, and PHP code with block-by-block activation control.', 'bbse' ); ?></p>
                    <?php if ( $total_count > 0 ) : ?>
                    <p class="bbse-stats">
                        <span id="bbse-stats-active" class="bbse-stats-active"><?php echo esc_html( $active_count ); ?></span>
                        <span class="bbse-stats-sep">/</span>
                        <span id="bbse-stats-total"><?php echo esc_html( $total_count ); ?></span>
                        <?php esc_html_e( 'blocks active', 'bbse' ); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="bbse-header-actions">
                    <div class="bbse-search-wrap">
                        <span class="bbse-search-icon dashicons dashicons-search"></span>
                        <input type="search" id="bbse-search" class="bbse-search-input" placeholder="<?php esc_attr_e( 'Search blocks...', 'bbse' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search blocks', 'bbse' ); ?>">
                        <button type="button" class="bbse-search-clear" id="bbse-search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'bbse' ); ?>" title="<?php esc_attr_e( 'Clear', 'bbse' ); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="bbse-header-actions-divider" aria-hidden="true"></div>
                    <div class="bbse-sort-wrap">
                        <label for="bbse-sort" class="bbse-sort-label">
                            <span class="dashicons dashicons-sort"></span>
                            <?php esc_html_e( 'Sort:', 'bbse' ); ?>
                        </label>
                        <select id="bbse-sort" class="bbse-sort-select">
                            <option value="active-first" selected><?php esc_html_e( 'Active first', 'bbse' ); ?></option>
                            <option value="inactive-first"><?php esc_html_e( 'Inactive first', 'bbse' ); ?></option>
                            <option value="name-asc"><?php esc_html_e( 'Name A–Z', 'bbse' ); ?></option>
                            <option value="name-desc"><?php esc_html_e( 'Name Z–A', 'bbse' ); ?></option>
                            <option value="type"><?php esc_html_e( 'By type', 'bbse' ); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="bbse-notification" class="bbse-notification" aria-live="polite"></div>

            <div class="bbse-toolbar">
                <button type="button" class="bbse-btn bbse-btn-primary" id="bbse-add-block">
                    <span class="dashicons dashicons-plus-alt2"></span>
                    <?php esc_html_e( 'Add New Block', 'bbse' ); ?>
                </button>
                <button type="button" class="bbse-btn bbse-btn-secondary" id="bbse-sync-remote">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Sync from Knowledgebase', 'bbse' ); ?>
                </button>
                <?php if ( ! empty( $blocks ) ) : ?>
                <button type="button" class="bbse-btn bbse-btn-danger bbse-btn-delete-all" id="bbse-delete-all">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Delete All', 'bbse' ); ?>
                </button>
                <?php endif; ?>
            </div>

            <div class="bbse-blocks-grid" id="bbse-blocks-container">
                <?php if ( empty( $blocks ) ) : ?>
                    <div class="bbse-empty-state">
                        <div class="bbse-empty-icon">
                            <span class="dashicons dashicons-editor-code"></span>
                        </div>
                        <h3><?php esc_html_e( 'No code blocks yet', 'bbse' ); ?></h3>
                        <p><?php esc_html_e( 'Click "Add New Block" to create your first CSS, JS, or PHP code block.', 'bbse' ); ?></p>
                        <button type="button" class="bbse-btn bbse-btn-primary" id="bbse-add-first">
                            <?php esc_html_e( 'Add First Block', 'bbse' ); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <div class="bbse-no-results" id="bbse-no-results" aria-live="polite">
                        <p><?php esc_html_e( 'No blocks match your search. Try a different term.', 'bbse' ); ?></p>
                    </div>
                    <?php foreach ( $blocks as $block ) : ?>
                        <?php self::render_block_card( $block ); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="bbse-pagination" id="bbse-pagination"></div>
        </div>

        <div id="bbse-edit-modal" class="bbse-modal" aria-hidden="true">
            <div class="bbse-modal-backdrop"></div>
            <div class="bbse-modal-content">
                <div class="bbse-modal-header">
                    <h2 id="bbse-modal-title"><?php esc_html_e( 'Edit Code Block', 'bbse' ); ?></h2>
                    <button type="button" class="bbse-modal-close" aria-label="<?php esc_attr_e( 'Close', 'bbse' ); ?>">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <form id="bbse-edit-form" class="bbse-modal-body">
                    <input type="hidden" name="block_id" id="bbse-edit-block-id" value="">
                    <div class="bbse-form-group">
                        <label for="bbse-edit-name"><?php esc_html_e( 'Block Name', 'bbse' ); ?></label>
                        <input type="text" id="bbse-edit-name" name="name" class="bbse-input" required>
                    </div>
                    <div class="bbse-form-group bbse-code-tabs">
                        <ul class="bbse-tabs" role="tablist">
                            <li><button type="button" class="bbse-tab active" data-tab="css">CSS</button></li>
                            <li><button type="button" class="bbse-tab" data-tab="js">JavaScript</button></li>
                            <li><button type="button" class="bbse-tab" data-tab="php">PHP</button></li>
                        </ul>
                        <div class="bbse-tab-panel active" id="tab-css">
                            <textarea name="css_code" id="bbse-edit-css" class="bbse-code-input" rows="10" placeholder="<?php esc_attr_e( '/* Your CSS code here */', 'bbse' ); ?>"></textarea>
                        </div>
                        <div class="bbse-tab-panel" id="tab-js">
                            <textarea name="js_code" id="bbse-edit-js" class="bbse-code-input" rows="10" placeholder="<?php esc_attr_e( '// Your JavaScript code here', 'bbse' ); ?>"></textarea>
                        </div>
                        <div class="bbse-tab-panel" id="tab-php">
                            <textarea name="php_code" id="bbse-edit-php" class="bbse-code-input" rows="10" placeholder="<?php esc_attr_e( 'Your PHP code here', 'bbse' ); ?>"></textarea>
                        </div>
                    </div>
                </form>
                <div class="bbse-modal-footer">
                    <button type="button" class="bbse-btn bbse-btn-secondary bbse-modal-close"><?php esc_html_e( 'Cancel', 'bbse' ); ?></button>
                    <button type="button" class="bbse-btn bbse-btn-primary" id="bbse-save-block"><?php esc_html_e( 'Save Block', 'bbse' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function get_editor_settings( $settings, $type ) {
        if ( ! is_array( $settings ) || empty( $settings['codemirror'] ) ) {
            return null;
        }
        $settings = array_merge( $settings, array(
            'codemirror' => array_merge( $settings['codemirror'], array(
                'mode' => $type,
                'lint' => false,
                'lineNumbers' => true,
                'lineWrapping' => true,
            ) ),
        ) );
        return $settings;
    }

    public static function render_block_card( $block ) {
        $is_active = ! empty( $block['is_active'] );
        $has_css   = ! empty( $block['has_css'] );
        $has_js    = ! empty( $block['has_js'] );
        $has_php   = ! empty( $block['has_php'] );
        $types     = array_filter( array(
            $has_css ? 'CSS' : null,
            $has_js ? 'JS' : null,
            $has_php ? 'PHP' : null,
        ) );
        $types_str = ! empty( $types ) ? implode( ', ', $types ) : __( 'Empty', 'bbse' );
        $types_lower = ! empty( $types ) ? implode( ' ', array_map( 'strtolower', $types ) ) : 'empty';
        ?>
        <div class="bbse-card" data-block-id="<?php echo esc_attr( $block['id'] ); ?>" data-block-name="<?php echo esc_attr( strtolower( $block['name'] ) ); ?>" data-block-types="<?php echo esc_attr( $types_lower ); ?>" data-block-status="<?php echo $is_active ? 'active' : 'inactive'; ?>">
            <div class="bbse-card-content">
                <div class="bbse-card-header">
                    <h3 class="bbse-card-title"><?php echo esc_html( $block['name'] ); ?></h3>
                    <div class="bbse-switch-wrapper">
                        <label class="bbse-switch" aria-label="<?php esc_attr_e( 'Toggle block activation', 'bbse' ); ?>">
                            <input type="checkbox" class="bbse-switch-input" <?php checked( $is_active ); ?>>
                            <span class="bbse-switch-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="bbse-card-meta">
                    <span class="bbse-badge"><?php echo esc_html( $types_str ); ?></span>
                    <span class="bbse-status <?php echo $is_active ? 'active' : 'inactive'; ?>">
                        <?php echo $is_active ? esc_html__( 'Active', 'bbse' ) : esc_html__( 'Inactive', 'bbse' ); ?>
                    </span>
                </div>
                <div class="bbse-card-actions">
                    <button type="button" class="bbse-btn bbse-btn-sm bbse-edit-block" data-block-id="<?php echo esc_attr( $block['id'] ); ?>">
                        <span class="dashicons dashicons-edit"></span>
                        <?php esc_html_e( 'Edit', 'bbse' ); ?>
                    </button>
                    <button type="button" class="bbse-btn bbse-btn-sm bbse-btn-danger bbse-delete-block" data-block-id="<?php echo esc_attr( $block['id'] ); ?>" data-block-name="<?php echo esc_attr( $block['name'] ); ?>">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e( 'Delete', 'bbse' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
