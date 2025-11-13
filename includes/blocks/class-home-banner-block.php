<?php
/**
 * Home Banner Section Block Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class RESTBridge_Home_Banner_Block {
    public function __construct() {
        add_action('init', [$this, 'register_block']);
    }

    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }

        $dir = plugin_dir_path(__FILE__) . 'home-banner-section/';
        $url = plugin_dir_url(__FILE__) . 'home-banner-section/';

        $metadata_path = $dir . 'block.json';

        if (!file_exists($metadata_path)) {
            error_log('RESTBridge Home Banner Section: block.json not found at ' . $metadata_path);
            return;
        }

        register_block_type_from_metadata($metadata_path);
    }
}

new RESTBridge_Home_Banner_Block();

add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');

    add_filter('rest_pre_serve_request', function ($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        return $value;
    });
});
