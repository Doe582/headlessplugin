<?php

class RESTBridge_WooCommerce_Manager {

    public function __construct() {
        add_action('woocommerce_api_loaded', [$this, 'custom_woocommerce_api_setup']);
    }

    public function custom_woocommerce_api_setup() {
        // WooCommerce API customizations if needed.
    }
}
