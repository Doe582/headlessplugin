<?php

class RESTBridge_Plugin {

    public function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
    }

    private function load_dependencies() {
        // WordPress Core API
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-posts-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-pages-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-comments-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-categories-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-tags-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-users-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-media-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-taxonomies-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-menus-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-custom-post-types-api.php';
        
        // WooCommerce API
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-products-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-cart-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-coupons-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-customers-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-orders-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-reports-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-settings-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-shipping-zones-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-shipping-methods-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-taxes-api.php';
        
        // Content & WooCommerce Managers
        require_once plugin_dir_path(__FILE__) . '../content/class-post-manager.php';
        require_once plugin_dir_path(__FILE__) . '../woocommerce/class-woocommerce-manager.php';
        require_once plugin_dir_path(__FILE__) . '../woocommerce/class-woocommerce-settings.php';
        require_once plugin_dir_path(__FILE__) . '../admin/class-settings.php';
    }

    private function define_hooks() {
        add_action('rest_api_init', [$this, 'register_api_routes']);
        add_action('admin_menu', [$this, 'add_plugin_menu']);
        add_action('init', [$this, 'init_content_manager']);
        add_action('init', [$this, 'init_woocommerce_manager']);
    }

    public function run() {
        // Start plugin if needed
    }

    public function register_api_routes() {
        // WordPress Core APIs
        $posts_api = new RESTBridge_Posts_API();
        $posts_api->register_routes();

        $pages_api = new RESTBridge_Pages_API();
        $pages_api->register_routes();

        $comments_api = new RESTBridge_Comments_API();
        $comments_api->register_routes();

        $categories_api = new RESTBridge_Categories_API();
        $categories_api->register_routes();

        $tags_api = new RESTBridge_Tags_API();
        $tags_api->register_routes();

        $users_api = new RESTBridge_Users_API();
        $users_api->register_routes();

        $media_api = new RESTBridge_Media_API();
        $media_api->register_routes();

        $taxonomies_api = new RESTBridge_Taxonomies_API();
        $taxonomies_api->register_routes();

        $menus_api = new RESTBridge_Menus_API();
        $menus_api->register_routes();

        $cpt_api = new RESTBridge_Custom_Post_Types_API();
        $cpt_api->register_routes();

        // WooCommerce APIs
        if (class_exists('WooCommerce')) {
            $products_api = new RESTBridge_Products_API();
            $products_api->register_routes();

            $cart_api = new RESTBridge_Cart_API();
            $cart_api->register_routes();

            $coupons_api = new RESTBridge_Coupons_API();
            $coupons_api->register_routes();

            $customers_api = new RESTBridge_Customers_API();
            $customers_api->register_routes();

            $orders_api = new RESTBridge_Orders_API();
            $orders_api->register_routes();

            $reports_api = new RESTBridge_Reports_API();
            $reports_api->register_routes();

            $settings_api = new RESTBridge_WooCommerce_Settings_API();
            $settings_api->register_routes();

            $shipping_zones_api = new RESTBridge_Shipping_Zones_API();
            $shipping_zones_api->register_routes();

            $shipping_methods_api = new RESTBridge_Shipping_Methods_API();
            $shipping_methods_api->register_routes();

            $taxes_api = new RESTBridge_Taxes_API();
            $taxes_api->register_routes();
        }
    }

    public function add_plugin_menu() {
        $settings = new RESTBridge_Settings();
        $settings->add_menu_page();
    }

    public function init_content_manager() {
        new RESTBridge_Post_Manager();
    }

    public function init_woocommerce_manager() {
        if (class_exists('WooCommerce')) {
            new RESTBridge_WooCommerce_Manager();
            new RESTBridge_WooCommerce_Settings();
        }
    }
}
