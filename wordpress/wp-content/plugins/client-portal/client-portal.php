<?php
/**
 * Plugin Name: VHONA Client Portal
 * Description: Provides a client portal experience similar to Suitdash with custom post types, REST API endpoints, and React bundle bootstrapping.
 * Version: 0.1.0
 * Author: VHONA AI Studio
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'includes/class-vhona-client-portal.php';

/**
 * Initialize the plugin.
 */
function vhona_client_portal_init() {
    \VHONA\ClientPortal\Plugin::instance();
}
add_action('plugins_loaded', 'vhona_client_portal_init');

/**
 * Activation hook.
 */
function vhona_client_portal_activate() {
    \VHONA\ClientPortal\Plugin::instance()->activate();
}
register_activation_hook(__FILE__, 'vhona_client_portal_activate');

/**
 * Deactivation hook.
 */
function vhona_client_portal_deactivate() {
    \VHONA\ClientPortal\Plugin::instance()->deactivate();
}
register_deactivation_hook(__FILE__, 'vhona_client_portal_deactivate');
