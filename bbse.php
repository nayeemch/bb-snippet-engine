<?php
/**
 * Plugin Name: BBSE - BuddyBoss Snippet Engine
 * Plugin URI: https://nayeemch.github.io/bb-snippet-engine/
 * Description: A modular snippet engine for BuddyBoss that lets you add, manage, and organize custom PHP, JS, and CSS code blocks to extend functionality and customize design—without modifying core files.
 * Version: 1.1.0
 * Author: BBSE
 * Text Domain: bbse
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BBSE_VERSION', '1.1.0' );
define( 'BBSE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BBSE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BBSE_REQUIRED_PLUGIN', 'buddyboss-platform/bp-loader.php' );
define( 'BBSE_GIST_URL', 'https://gist.github.com/nayeemch/93e3690d7776ef2b1f67c67fa2d49d49' );

/**
 * Check if BuddyBoss Platform is active.
 *
 * @return bool
 */
function bbse_is_buddyboss_active() {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active( BBSE_REQUIRED_PLUGIN );
}

/**
 * Prevent activation if BuddyBoss is missing.
 */
function bbse_activate_plugin() {
    if ( ! bbse_is_buddyboss_active() ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die(
            esc_html__( 'BBSE requires the BuddyBoss Platform plugin to be installed and activated.', 'bbse' ),
            esc_html__( 'Plugin dependency check', 'bbse' ),
            array( 'back_link' => true )
        );
    }
}
register_activation_hook( __FILE__, 'bbse_activate_plugin', 5 );

/**
 * Admin notice if BuddyBoss is not active.
 */
function bbse_admin_notice_missing_buddyboss() {
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e( 'BBSE', 'bbse' ); ?></strong>
            <?php esc_html_e( 'requires the BuddyBoss Platform plugin to be installed and activated.', 'bbse' ); ?>
        </p>
    </div>
    <?php
}

/**
 * Stop plugin execution if BuddyBoss is not active.
 *
 * @return bool True if BuddyBoss is active, false otherwise.
 */
function bbse_dependency_check() {
    if ( ! bbse_is_buddyboss_active() ) {
        add_action( 'admin_notices', 'bbse_admin_notice_missing_buddyboss' );
        return false;
    }
    return true;
}

if ( ! bbse_dependency_check() ) {
    return;
}

require_once BBSE_PLUGIN_DIR . 'includes/class-bbse-database.php';
require_once BBSE_PLUGIN_DIR . 'includes/class-bbse-remote-sync.php';
require_once BBSE_PLUGIN_DIR . 'includes/class-bbse-injector.php';
require_once BBSE_PLUGIN_DIR . 'includes/class-bbse-ajax.php';
require_once BBSE_PLUGIN_DIR . 'admin/class-bbse-admin.php';

class BBSE {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        BBSE_Database::init();
        BBSE_Injector::init();
        BBSE_Ajax::init();
        BBSE_Admin::init();
    }

    public function activate() {
        BBSE_Database::create_tables();
        self::maybe_create_sample_block();
    }

    private static function maybe_create_sample_block() {
        $blocks = BBSE_Database::get_all_blocks();
        if ( empty( $blocks ) ) {

            // Block 1: BuddyBoss Full Width Layout
            BBSE_Database::insert_block( array(
                'name'      => __( 'BuddyBoss Full Width Layout', 'bbse' ),
                'css_code'  => "/* START of WIDE-SCREEN STYLING */\n@media screen and (min-width:1201px) {\n    .container {\n        max-width: 1800px; /* stretch limit */\n    }\n    #primary {\n        max-width: 100% !important;\n        flex: 3 0 250px;\n    }\n    .widget-area:not(.widget-area-secondary) {\n        -webkit-box-flex: 1;\n        -ms-flex: 1 0 180px;\n        flex: 1 0 180px;\n        max-width: 550px; /* stretch limit */\n        min-width: 280px; /* shrink limit */\n    }\n    /* Forum */\n    .activity-list li.bbp_reply_create .activity-content .activity-inner, \n    .activity-list li.bbp_topic_create .activity-content .activity-inner, \n    .activity-list li.blogs .activity-content .activity-inner {\n        max-width: 100%;\n    }\n    /* Forum Images */\n    .bb-media-length-1 .bb-activity-media-elem .entry-img img {\n/*         min-width: 100%; */\n    }\n    .activity-list li.bbp_reply_create .bb-activity-media-wrap, \n    .activity-list li.bbp_topic_create .bb-activity-media-wrap {\n        padding: 0 10px;\n    }\n    /* Video & Docs */\n    .bb-activity-media-wrap, \n    .bb-activity-video-wrap, \n    .activity-list .bb-video-wrapper, \n    .video-activity-wrap {\n        max-width: initial;\n    }\n    /* Photos */\n    .bb-media-length-1 .bb-activity-media-elem.media-activity {\n        flex: 0 0 100%;\n    }\n}\n/* END of WIDE-SCREEN STYLING */",
                'js_code'   => "",
                'php_code'  => '',
                'is_active' => 0,
            ) );

            // Block 2: Activity image filter on BB
            BBSE_Database::insert_block( array(
                'name'      => __( 'Activity image filter on BB', 'bbse' ),
                'css_code'  => ".bb-activity-media-elem {\n display: flex !important;\n justify-content: center !important; /* horizontal center */\n align-items: center !important; /* vertical center */\n}\n.bb-activity-media-elem .entry-img {\n display: flex !important;\n justify-content: center !important;\n align-items: center !important;\n width: 100% !important;\n}\n.bb-activity-media-elem img {\n max-width: 100% !important;\n height: auto !important;\n display: block !important;\n}\n.bb-activity-media-wrap {\n max-width: 100% !important;\n}\n.bb-media-length-1 .bb-activity-media-elem.media-activity {\n flex: auto !important;\n}\n\n/* Container */\n.bb-activity-media-elem {\n position: relative !important;\n overflow: hidden !important;\n border-radius: 16px; /* optional modern rounded look */\n padding: 0 !important;\n}\n\n/* Blurred cloned image (background layer) */\n.bb-activity-media-elem .bb-blur-bg {\n position: absolute;\n inset: 0;\n width: 100%;\n height: 100%;\n object-fit: cover;\n filter: blur(40px) brightness(0.6);\n transform: scale(1.3);\n z-index: 0;\n pointer-events: none;\n}\n\n/* Glass overlay layer */\n.bb-activity-media-elem::after {\n content: \"\";\n position: absolute;\n inset: 0;\n backdrop-filter: blur(8px);\n background: rgba(255, 255, 255, 0.08);\n z-index: 1;\n}\n\n/* Sharp image wrapper */\n.bb-activity-media-elem .entry-img {\n position: relative;\n z-index: 2;\n display: flex !important;\n justify-content: center;\n align-items: center;\n}\n\n/* Front image */\n.bb-activity-media-elem .entry-img img {\n max-width: 100%;\n height: auto;\n box-shadow: 0 20px 40px rgba(0,0,0,0.25);\n}",
                'js_code'   => "jQuery(document).ready(function($){\n\n function addGlassBlur() {\n\n $(\".bb-activity-media-elem\").each(function(){\n\n var \$container = $(this);\n var \$img = \$container.find(\".entry-img img\");\n\n if (!\$img.length) return;\n\n if (\$container.find(\".bb-blur-bg\").length) return;\n\n var \$clone = \$img.clone()\n .removeAttr(\"style\")\n .addClass(\"bb-blur-bg\");\n\n \$container.prepend(\$clone);\n });\n }\n\n addGlassBlur();\n\n // For dynamic content (BuddyBoss infinite scroll safe)\n $(document).ajaxComplete(function(){\n addGlassBlur();\n });\n\n});",
                'php_code'  => '',
                'is_active' => 0,
            ) );

            // Block 3: Back to Top Button BB
            BBSE_Database::insert_block( array(
                'name'      => __( 'Back to Top Button BB', 'bbse' ),
                'css_code'  => '',
                'js_code'   => "const backToTop = document.createElement('button');\nbackToTop.innerHTML = '↑';\nbackToTop.className = 'back-to-top';\nbackToTop.style.cssText = `\n position: fixed;\n bottom: 20px;\n right: 20px;\n padding: 10px 15px;\n background: #007bff;\n color: white;\n border: none;\n border-radius: 5px;\n cursor: pointer;\n opacity: 0;\n transition: opacity 0.3s;\n z-index: 1000;\n`;\n\nwindow.addEventListener('scroll', () => {\n backToTop.style.opacity = window.scrollY > 300 ? '1' : '0';\n});\n\nbackToTop.addEventListener('click', () => {\n window.scrollTo({ top: 0, behavior: 'smooth' });\n});\n\ndocument.body.appendChild(backToTop);",
                'php_code'  => '',
                'is_active' => 0,
            ) );

            // Block 4: Center Login Logo on BB
            BBSE_Database::insert_block( array(
                'name'      => __( 'Center Login Logo on BB', 'bbse' ),
                'css_code'  => '',
                'js_code'   => '',
                'php_code'  => "function bb_hook_login_enqueue_scripts() { ?>\n <style>\n body.login.login-split-page #login h1 a {\n margin-left: auto !important;\n }\n </style>\n<?php }\nadd_action( 'login_enqueue_scripts', 'bb_hook_login_enqueue_scripts', 1 );\n",
                'is_active' => 0,
            ) );

            // Block 5: Remove WordPress wording on Site title
            BBSE_Database::insert_block( array(
                'name'      => __( 'Remove WordPress wording on Site title', 'bbse' ),
                'css_code'  => '',
                'js_code'   => '',
                'php_code'  => "add_filter( 'login_title', 'custom_login_title' );\n\nfunction custom_login_title() {\n \$title = 'Log In';\n return get_bloginfo('name').' ‹ '.\$title;\n}\n",
                'is_active' => 0,
            ) );

            // Block 6: Automatically disable Registration validation, activation key and approve pending users
            BBSE_Database::insert_block( array(
                'name'      => __( 'Automatically disable Registration validation, activation key and approve pending users', 'bbse' ),
                'css_code'  => '',
                'js_code'   => '',
                'php_code'  => "function disable_validation( \$user_id ) {\n global \$wpdb;\n\n // Ensure the user is marked as active\n \$wpdb->query(\n \$wpdb->prepare(\n \"UPDATE \$wpdb->users SET user_status = 0 WHERE ID = %d\",\n \$user_id\n )\n );\n\n // Get all inactive signups\n \$users = \$wpdb->get_results(\n \"SELECT activation_key, user_login FROM {\$wpdb->prefix}signups WHERE active = '0'\"\n );\n\n foreach ( \$users as \$user ) {\n\n // Activate the signup\n bp_core_activate_signup( \$user->activation_key );\n BP_Signup::validate( \$user->activation_key );\n\n // Assign default role\n \$user_id = \$wpdb->get_var(\n \"SELECT ID FROM \$wpdb->users WHERE user_login = '\$user->user_login'\"\n );\n\n if ( \$user_id ) {\n \$u = new WP_User( \$user_id );\n \$u->add_role( 'subscriber' );\n }\n }\n}\n\nadd_action( 'bp_core_signup_user', 'disable_validation' );\nadd_filter( 'bp_registration_needs_activation', '__return_false' );\nadd_filter( 'bp_core_signup_send_activation_key', '__return_false' );",
                'is_active' => 0,
            ) );

            // Block 7: Disable pressing "Enter" from sending the private message
            BBSE_Database::insert_block( array(
                'name'      => __( 'Disable pressing "Enter" from sending the private message', 'bbse' ),
                'css_code'  => '',
                'js_code'   => "jQuery('#message_content').bind('keydown', function(e) {\n    if (e.keyCode == 13) {\n        e.preventDefault();\n        }\n    });",
                'php_code'  => '',
                'is_active' => 0,
            ) );

            // Block 8: How to Change the "Sign in" Button Text
            BBSE_Database::insert_block( array(
                'name'      => __( 'How to Change the "Sign in" Button Text', 'bbse' ),
                'css_code'  => '',
                'js_code'   => "document.addEventListener(\"DOMContentLoaded\", function() { const signInButton = document.querySelector(\"a.button.small.outline.signin-button.link\"); if (signInButton) { signInButton.innerText = \"Text Here\"; }});",
                'php_code'  => '',
                'is_active' => 0,
            ) );

        }
    }

    public function deactivate() {
        // Optional cleanup
    }
}

BBSE::instance();
