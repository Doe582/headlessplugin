<?php

class RESTBridge_WooCommerce_Manager {

    public function __construct() {
        add_action('woocommerce_api_loaded', [$this, 'custom_woocommerce_api_setup']);
        add_action('init', [$this, 'register_product_fabric_taxonomy']);
    }

    public function custom_woocommerce_api_setup() {
        // WooCommerce API customizations if needed.
    }

    /**
     * Register custom Fabric taxonomy for products
     */
    public function register_product_fabric_taxonomy() {
        $labels = [
            'name'              => 'Fabrics',
            'singular_name'     => 'Fabric',
            'search_items'      => 'Search Fabrics',
            'all_items'         => 'All Fabrics',
            'edit_item'         => 'Edit Fabric',
            'update_item'       => 'Update Fabric',
            'add_new_item'      => 'Add New Fabric',
            'new_item_name'     => 'New Fabric Name',
            'menu_name'         => 'Fabric',
        ];

        $args = [
            'hierarchical'      => true,  // works like categories (parent/child)
            'labels'            => $labels,
            'show_ui'           => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'fabric'],
            'show_admin_column' => true,
            'show_in_rest'      => true,  // IMPORTANT for Store API + Block Editor
        ];

        register_taxonomy(
            'fabric',  // taxonomy name
            'product', // attach to WooCommerce products
            $args
        );
    }
}
// add_filter('woocommerce_store_api_disable_nonce_check', '__return_true');