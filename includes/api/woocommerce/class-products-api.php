<?php

class RESTBridge_Products_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products', [
            'methods' => 'GET',
            'callback' => [$this, 'get_products'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products', [
            'methods' => 'POST',
            'callback' => [$this, 'create_product'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)/popup', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_popup'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_product'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_product'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_products(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $params = $request->get_query_params();
        
        $args = [
            'post_type' => 'product',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
            'paged' => isset($params['page']) ? (int) $params['page'] : 1,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
        ];

        if (isset($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        if (isset($params['category'])) {
            $args['product_cat'] = sanitize_text_field($params['category']);
        }

        $query = new WP_Query($args);
        $products = [];

        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if ($product) {
                $products[] = $this->format_product($product);
            }
        }
        wp_reset_postdata();

        return rest_ensure_response([
            'products' => $products,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ]);
    }

    public function get_product(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $product_id = (int) $request['id'];
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }

        return rest_ensure_response($this->format_product($product));
    }

    public function get_product_popup(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $product_id = (int) $request['id'];
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }

        $detail = RESTBridge_Store_API_Product_Detail::get_instance();
        if (!$detail) {
            $detail = new RESTBridge_Store_API_Product_Detail();
        }

        $payload = $detail->get_popup_payload($product);

        if (empty($payload)) {
            return new WP_Error('popup_unavailable', 'Popup data could not be generated', ['status' => 500]);
        }

        return rest_ensure_response($payload);
    }

    public function create_product(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $params = $request->get_json_params();

        $product = new WC_Product_Simple();
        $product->set_name(isset($params['name']) ? sanitize_text_field($params['name']) : '');
        $product->set_description(isset($params['description']) ? wp_kses_post($params['description']) : '');
        $product->set_short_description(isset($params['short_description']) ? sanitize_textarea_field($params['short_description']) : '');
        
        if (isset($params['price'])) {
            $product->set_regular_price((float) $params['price']);
        }

        if (isset($params['status'])) {
            $product->set_status(sanitize_text_field($params['status']));
        }

        $product_id = $product->save();

        if (!$product_id) {
            return new WP_Error('product_failed', 'Failed to create product', ['status' => 500]);
        }

        return rest_ensure_response($this->format_product(wc_get_product($product_id)), 201);
    }

    public function update_product(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $product_id = (int) $request['id'];
        $params = $request->get_json_params();

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }

        if (isset($params['name'])) {
            $product->set_name(sanitize_text_field($params['name']));
        }
        if (isset($params['description'])) {
            $product->set_description(wp_kses_post($params['description']));
        }
        if (isset($params['short_description'])) {
            $product->set_short_description(sanitize_textarea_field($params['short_description']));
        }
        if (isset($params['price'])) {
            $product->set_regular_price((float) $params['price']);
        }
        if (isset($params['status'])) {
            $product->set_status(sanitize_text_field($params['status']));
        }

        $product->save();

        return rest_ensure_response($this->format_product($product));
    }

    public function delete_product(WP_REST_Request $request) {
        if (!function_exists('WC')) {
            return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
        }

        $product_id = (int) $request['id'];
        $force = isset($request['force']) && $request['force'];

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }

        $result = wp_delete_post($product_id, $force);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete product', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $product_id]);
    }

    private function format_product($product) {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => $product->get_permalink(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'average_rating' => number_format((float) $product->get_average_rating(), 2, '.', ''),
            'review_count' => (int) $product->get_review_count(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
            'status' => $product->get_status(),
            'featured_image' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
            'gallery' => array_map(function($id) {
                return wp_get_attachment_image_url($id, 'full');
            }, $product->get_gallery_image_ids()),
            'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']),
            'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'ids']),
        ];
    }

    public function check_permission($request = null) {
        $user_id = get_current_user_id();
        
        // Fallback: Try Basic Auth if not authenticated
        if (!$user_id && $request) {
            $auth_header = $request->get_header('authorization');
            if ($auth_header && preg_match('/Basic\s+(.+)$/i', $auth_header, $matches)) {
                $credentials = base64_decode($matches[1]);
                if (strpos($credentials, ':') !== false) {
                    list($username, $password) = explode(':', $credentials, 2);
                    $user = wp_authenticate($username, $password);
                    if (!is_wp_error($user)) {
                        wp_set_current_user($user->ID);
                        $user_id = $user->ID;
                    }
                }
            }
        }
        
        return $user_id && (current_user_can('manage_woocommerce') || current_user_can('manage_options') || in_array('administrator', (array)wp_get_current_user()->roles));
    }
}

