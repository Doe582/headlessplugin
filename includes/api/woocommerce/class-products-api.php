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

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)/review', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_product_review'],
            'permission_callback' => [$this, 'check_permission'],
        ]);


        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_product'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Register compare endpoint
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/products/compare/(?P<ids>[\d,]+)', [
            'methods'  => 'GET',
            'callback' => [$this, 'compare_products'],
            'permission_callback' => '__return_true',
            'args' => [
                'ids' => [
                    'required' => true,
                    'description' => 'Comma separated product IDs',
                ],
            ],
        ]); 
    }
    /**
     * Compare products by IDs
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function compare_products(WP_REST_Request $request) {

        // ids from URL → "12,45,78"
        $ids_param = $request->get_param('ids');

        if (empty($ids_param)) {
            return new WP_Error(
                'invalid_ids',
                'Product IDs are required.',
                ['status' => 400]
            );
        }

        // Convert to array
        $ids = array_filter(array_map('intval', explode(',', $ids_param)));

        if (empty($ids)) {
            return new WP_Error(
                'invalid_ids',
                'No valid product IDs provided.',
                ['status' => 400]
            );
        }

        $products = [];

        foreach ($ids as $id) {

            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }

            $formatted = $this->format_product($product);

            // Add categories with name + slug
            $formatted['categories'] = $this->get_product_terms(
                $product->get_id(),
                'product_cat'
            );

            // Add tags with name + slug
            $formatted['tags'] = $this->get_product_terms(
                $product->get_id(),
                'product_tag'
            );

            $products[] = $formatted;
        }

        if (empty($products)) {
            return new WP_Error(
                'no_products_found',
                'No valid products found.',
                ['status' => 404]
            );
        }

        return rest_ensure_response([
            'count'    => count($products),
            'products' => $products,
        ]);
    }
    private function get_product_terms($product_id, $taxonomy) {

        $terms = get_the_terms($product_id, $taxonomy);

        if (empty($terms) || is_wp_error($terms)) {
            return [];
        }

        return array_map(function ($term) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, $terms);
    }

    public function add_product_review(WP_REST_Request $request) {

    $product_id = absint($request['id']);
    $rating     = intval($request->get_param('rating'));
    $review     = sanitize_textarea_field($request->get_param('review'));

    // Validate product
    if (!wc_get_product($product_id)) {
        return new WP_Error('invalid_product', 'Invalid product ID', ['status' => 404]);
    }

    // Validate rating
    if ($rating < 1 || $rating > 5) {
        return new WP_Error('invalid_rating', 'Rating must be between 1 and 5', ['status' => 400]);
    }

    $user_id = get_current_user_id();

    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in to add a review', ['status' => 401]);
    }

    // Prepare comment data
    $comment_data = [
        'comment_post_ID'      => $product_id,
        'comment_author'       => wp_get_current_user()->display_name,
        'comment_author_email' => wp_get_current_user()->user_email,
        'comment_content'      => $review,
        'comment_type'         => 'review',
        'comment_approved'     => 1,
        'user_id'              => $user_id,
    ];

    // Insert comment
    $comment_id = wp_insert_comment($comment_data);

    if (!$comment_id) {
        return new WP_Error('review_failed', 'Failed to add review', ['status' => 500]);
    }

    // Add rating meta
    update_comment_meta($comment_id, 'rating', $rating);

    return [
        'success'    => true,
        'message'    => 'Review added successfully',
        'review_id'  => $comment_id,
        'product_id' => $product_id,
        'rating'     => $rating,
    ];
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

        if ($request instanceof WP_REST_Request) {
            $request->get_json_params();
        }

        $user_id = get_current_user_id();

        if (!$user_id && $request) {

            $auth_header = $request->get_header('authorization');

            if (!$auth_header) {
                $token = $request->get_param('token');

                if ($token) {
                    $users = get_users([
                        'meta_key'   => '_api_token',
                        'meta_value' => $token,
                        'number'     => 1,
                        'count_total'=> false,
                    ]);

                    if (!empty($users)) {
                        wp_set_current_user($users[0]->ID);
                        $user_id = $users[0]->ID;
                    }
                }
            }

            if (!$user_id && $auth_header && preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
                $token = $m[1];

                $users = get_users([
                    'meta_key'   => '_api_token',
                    'meta_value' => $token,
                    'number'     => 1,
                    'count_total'=> false,
                ]);

                if (!empty($users)) {
                    wp_set_current_user($users[0]->ID);
                    $user_id = $users[0]->ID;
                }
            }
        }

        if ($user_id && $user_id > 0) {
            return true;
        }

        return false;
    }
}

