<?php

class RESTBridge_Wishlist_API {
    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/wishlist', [
            'methods' => 'GET',
            'callback' => [$this, 'get_wishlist'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/wishlist/add', [
            'methods' => 'POST',
            'callback' => [$this, 'add_to_wishlist'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/wishlist/remove', [
            'methods' => 'POST',
            'callback' => [$this, 'remove_from_wishlist'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_wishlist(WP_REST_Request $request) {
        $auth = $this->maybe_authenticate_request_user($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $wishlist = $this->get_current_wishlist_items();
        $products = $this->format_wishlist_products($wishlist);

        return $this->build_wishlist_response($wishlist);
    }

    private function build_wishlist_response(array $wishlist) {
        $products = $this->format_wishlist_products($wishlist);

        return [
            'items' => $products,
            'ids' => $wishlist,
            'count' => count($wishlist),
            'logged_in' => is_user_logged_in(),
        ];
    }

    public function add_to_wishlist(WP_REST_Request $request) {
        $auth = $this->maybe_authenticate_request_user($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $product_id = $this->extract_product_id($request);
        if (!$product_id) {
            return new WP_Error('invalid_product_id', 'Valid product_id is required', ['status' => 400]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }

        $wishlist = $this->get_current_wishlist_items();

        if (!in_array($product_id, $wishlist, true)) {
            $wishlist[] = $product_id;
            $this->save_wishlist($wishlist);
        }

        return rest_ensure_response(
            $this->build_wishlist_response($wishlist)
        );
    }


    public function remove_from_wishlist(WP_REST_Request $request) {
        $auth = $this->maybe_authenticate_request_user($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        $product_id = $this->extract_product_id($request);
        if (!$product_id) {
            return new WP_Error('invalid_product_id', 'Valid product_id is required', ['status' => 400]);
        }

        $wishlist = $this->get_current_wishlist_items();
        $wishlist = array_values(array_diff($wishlist, [$product_id]));

        $this->save_wishlist($wishlist);

        return rest_ensure_response(
            $this->build_wishlist_response($wishlist)
        );
    }


    private function extract_product_id(WP_REST_Request $request) {
        $params = $request->get_json_params();
        if (isset($params['product_id'])) {
            return absint($params['product_id']);
        }
        return absint($request->get_param('product_id'));
    }

    private function get_current_wishlist_items() {
        if (function_exists('styluza_get_wishlist')) {
            return $this->normalize_wishlist(styluza_get_wishlist());
        }

        if (is_user_logged_in()) {
            $user_wishlist = get_user_meta(get_current_user_id(), 'styluza_wishlist_products', true);
            $user_wishlist = $this->normalize_wishlist($user_wishlist);
            if (!empty($user_wishlist)) {
                return $user_wishlist;
            }
        }

        return $this->get_session_wishlist_items();
    }

    private function save_wishlist(array $wishlist) {
        $wishlist = $this->normalize_wishlist($wishlist);

        if (function_exists('styluza_save_wishlist')) {
            styluza_save_wishlist($wishlist);
            return;
        }

        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), 'styluza_wishlist_products', $wishlist);
        }

        $this->ensure_wc_session();
        if (function_exists('WC') && WC() && WC()->session) {
            WC()->session->set('styluza_wishlist', $wishlist);
        }
    }

    private function get_session_wishlist_items() {
        if (function_exists('styluza_get_session_wishlist')) {
            return $this->normalize_wishlist(styluza_get_session_wishlist());
        }

        $this->ensure_wc_session();
        if (function_exists('WC') && WC() && WC()->session) {
            $session_wishlist = WC()->session->get('styluza_wishlist', []);
            return $this->normalize_wishlist($session_wishlist);
        }

        return [];
    }

    private function normalize_wishlist($wishlist) {
        if (!is_array($wishlist)) {
            $wishlist = [];
        }

        $wishlist = array_filter(
            array_map('intval', $wishlist),
            function ($id) {
                return $id > 0;
            }
        );

        return array_values(array_unique($wishlist));
    }

    private function ensure_wc_session() {
        if (function_exists('styluza_ensure_wc_session')) {
            styluza_ensure_wc_session();
            return;
        }

        if (function_exists('WC') && WC() && is_null(WC()->session) && method_exists(WC(), 'initialize_session')) {
            WC()->initialize_session();
        }
    }

    private function format_wishlist_products(array $wishlist_ids) {
        if (empty($wishlist_ids)) {
            return [];
        }

        $products = [];
        foreach ($wishlist_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $products[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'slug' => $product->get_slug(),
                'permalink' => $product->get_permalink(),
                'price_html' => $product->get_price_html(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail'),
                'stock_status' => $product->get_stock_status(),
                'in_stock' => $product->is_in_stock(),
                'average_rating' => number_format((float) $product->get_average_rating(), 2, '.', ''),
                'review_count' => (int) $product->get_review_count(),
            ];
        }

        return $products;
    }

    private function maybe_authenticate_request_user(WP_REST_Request $request = null, $require_token_if_present = false) {
        if (is_user_logged_in()) {
            return true;
        }

        if (!$request instanceof WP_REST_Request) {
            return true;
        }

        $token = $this->extract_bearer_token($request);
        if (empty($token)) {
            return $require_token_if_present ? new WP_Error('missing_bearer_token', 'Bearer token is required', ['status' => 401]) : true;
        }

        $user = $this->get_user_by_api_token($token);
        if (!$user) {
            return new WP_Error('invalid_bearer_token', 'Invalid bearer token', ['status' => 401]);
        }

        wp_set_current_user($user->ID);
        return true;
    }

    private function extract_bearer_token(WP_REST_Request $request) {
        $authorization = $request->get_header('authorization');
        if (!empty($authorization)) {
            if (stripos($authorization, 'bearer ') === 0) {
                return trim(substr($authorization, 7));
            }

            if (stripos($authorization, 'basic ') === 0) {
                $decoded = base64_decode(substr($authorization, 6));
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list(, $token) = explode(':', $decoded, 2);
                    return $token;
                }
            }
        }

        $header_token = $request->get_header('x-bearer-token');
        if (!empty($header_token)) {
            return $header_token;
        }

        $param_token = $request->get_param('bearer_token');
        if (!empty($param_token)) {
            return sanitize_text_field($param_token);
        }

        $legacy = $request->get_param('user_token');
        return !empty($legacy) ? sanitize_text_field($legacy) : '';
    }

    private function get_user_by_api_token($token) {
        $users = get_users([
            'meta_key' => '_api_token',
            'meta_value' => $token,
            'number' => 1,
            'count_total' => false,
        ]);

        return !empty($users) ? $users[0] : null;
    }
}

