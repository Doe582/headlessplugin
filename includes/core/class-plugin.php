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
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-bearer-token-auth.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-media-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-taxonomies-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-menus-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-custom-post-types-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-fluent-form-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/wordpress/class-html-api.php';
        
        // Elementor & Gutenberg APIs
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-content-parser-trait.php';
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-elementor-pages-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-gutenberg-pages-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-pages-content-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-header-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/elementor/class-footer-api.php';
        
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
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-store-api-filters.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-store-api-product-detail.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-store-api-cart-sync.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-wishlist-api.php';
        require_once plugin_dir_path(__FILE__) . '../api/woocommerce/class-product-filters-api.php';
        
        // Content & WooCommerce Managers
        require_once plugin_dir_path(__FILE__) . '../content/class-post-manager.php';
        require_once plugin_dir_path(__FILE__) . '../woocommerce/class-woocommerce-manager.php';
        require_once plugin_dir_path(__FILE__) . '../woocommerce/class-woocommerce-settings.php';
        require_once plugin_dir_path(__FILE__) . '../woocommerce/class-woocommerce-product-sorting.php';
        require_once plugin_dir_path(__FILE__) . '../admin/class-settings.php';
        
        // Blocks
        require_once plugin_dir_path(__FILE__) . '../blocks/class-home-banner-block.php';
        
    }

    private function define_hooks() {
        add_action('rest_api_init', [$this, 'register_api_routes']);
        add_action('rest_api_init', [$this, 'bootstrap_wc_for_rest']);
        add_action('admin_menu', [$this, 'add_plugin_menu']);
        add_action('init', [$this, 'init_content_manager']);
        add_action('init', [$this, 'init_woocommerce_manager']);
        add_action('init', [$this, 'init_blocks']);
    }
    
    public function init_blocks() {
        // Blocks are auto-initialized via their constructors
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

        $html_api = new RESTBridge_HTML_API();
        $html_api->register_routes();

        // FluentForm API
        if (class_exists('\FluentForm\App\Models\Submission')) {
            $fluent_form_api = new RESTBridge_FluentForm_API();
            $fluent_form_api->register_routes();
        }

        // Elementor & Gutenberg APIs
        $elementor_pages_api = new RESTBridge_Elementor_Pages_API();
        $elementor_pages_api->register_routes();

        $gutenberg_pages_api = new RESTBridge_Gutenberg_Pages_API();
        $gutenberg_pages_api->register_routes();

        $pages_content_api = new RESTBridge_Pages_Content_API();
        $pages_content_api->register_routes();

        $header_api = new RESTBridge_Header_API();
        $header_api->register_routes();

        $footer_api = new RESTBridge_Footer_API();
        $footer_api->register_routes();

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

            $wishlist_api = new RESTBridge_Wishlist_API();
            $wishlist_api->register_routes();

            $product_filters_api = new RESTBridge_Product_Filters_API();
            $product_filters_api->register_routes();
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
            // Initialize product sorting
            new RESTBridge_WooCommerce_Product_Sorting();
            // Initialize Store API filters
            new RESTBridge_Store_API_Filters();
            // Initialize Store API product detail extension
            new RESTBridge_Store_API_Product_Detail();
            // Initialize Store API cart sync (ensures cart visible on frontend)
            new RESTBridge_Store_API_Cart_Sync();
        }
    }

    /**
     * Ensure WooCommerce session and cart are ready for REST requests.
     * This ensures Store API requests properly initialize sessions for cart sync.
     *
     * @return void
     */
    public function bootstrap_wc_for_rest() {
        if (!function_exists('WC')) {
            return;
        }

        $wc = WC();

        // Load frontend helpers (sessions, cart hooks, etc.)
        if (method_exists($wc, 'frontend_includes')) {
            $wc->frontend_includes();
        }

        // Ensure a session handler exists
        if (empty($wc->session)) {
            if (method_exists($wc, 'initialize_session')) {
                $wc->initialize_session();
            } elseif (class_exists('WC_Session_Handler')) {
                $wc->session = new WC_Session_Handler();
                $wc->session->init();
            }
        } elseif (method_exists($wc->session, 'init')) {
            $wc->session->init();
        }

        // Load cart object if needed
        if (empty($wc->cart)) {
            wc_load_cart();
        }

        // For Store API requests, ensure cart is properly loaded from session
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wc/store/v1/') !== false) {
            // Ensure cart loads from session
            if ($wc->cart && method_exists($wc->cart, 'get_cart')) {
                $wc->cart->get_cart();
            }
        }

        // Ensure totals are calculated (important for mini cart)
        if ($wc->cart && method_exists($wc->cart, 'calculate_totals')) {
            $wc->cart->calculate_totals();
        }
    }
}
