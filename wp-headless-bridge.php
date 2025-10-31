<?php
/*
Plugin Name: WP REST Bridge
Description: Modular custom plugin for headless WordPress with dynamic REST API endpoints for posts, pages, comments, categories, tags, users, media, taxonomies and WooCommerce support.
Version: 1.0
Author: Brainflecks
Author URI: https://brainflecks.com
*/

if (!defined('ABSPATH')) {
    exit;
}

// Global REST API namespace for this plugin
if (!defined('RESTBRIDGE_API_NAMESPACE')) {
    define('RESTBRIDGE_API_NAMESPACE', 'restbridge/v1');
}

require_once plugin_dir_path(__FILE__) . 'includes/core/class-plugin.php';

function run_rest_bridge_plugin() {
    $plugin = new RESTBridge_Plugin();
    $plugin->run();
}

run_rest_bridge_plugin();
