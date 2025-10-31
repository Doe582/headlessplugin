<?php

class RESTBridge_WooCommerce_Settings {

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings() {
        register_setting('rest_bridge_wc_settings', 'rest_bridge_wc_option');
        add_settings_section('wc_settings_section', 'WooCommerce Settings', null, 'rest-bridge-plugin');
        add_settings_field('wc_option', 'Example Option', [$this, 'render_field'], 'rest-bridge-plugin', 'wc_settings_section');
    }

    public function render_field() {
        $value = get_option('rest_bridge_wc_option');
        echo '<input type="text" name="rest_bridge_wc_option" value="' . esc_attr($value) . '" />';
    }
}
