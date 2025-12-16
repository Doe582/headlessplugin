<?php

class RESTBridge_Account_API {

    public function register_routes() {

        // Full account overview
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account', [
            'methods' => 'GET',
            'callback' => [$this, 'get_account_overview'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Orders
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/orders', [
            'methods' => 'GET',
            'callback' => [$this, 'get_orders'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/orders/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_single_order_details'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Downloads
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/downloads', [
            'methods' => 'GET', 
            'callback' => [$this, 'get_downloads'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Addresses
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/address', [
            'methods' => 'GET',
            'callback' => [$this, 'get_addresses'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/address', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_address'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/address/update', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_addresses'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Account details
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/details', [
            'methods' => 'GET',
            'callback' => [$this, 'get_account_details'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/account/update', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_user_account'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/dashboard', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_custom_account_data'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    private function get_saved_addresses($user_id) {
        $data = get_user_meta($user_id, '_saved_addresses', true);
        return is_array($data) ? $data : [];
    }

    private function save_addresses($user_id, $addresses) {
        update_user_meta($user_id, '_saved_addresses', $addresses);
    }

    private function sync_default_with_woocommerce($user_id, $address) {
        update_user_meta($user_id, 'billing_address_1', $address['address_1']);
        update_user_meta($user_id, 'billing_address_2', $address['address_2']);
        update_user_meta($user_id, 'billing_city', $address['city']);
        update_user_meta($user_id, 'billing_state', $address['state']);
        update_user_meta($user_id, 'billing_postcode', $address['postcode']);
        update_user_meta($user_id, 'billing_country', $address['country']);
        update_user_meta($user_id, 'billing_phone', $address['phone']);

        update_user_meta($user_id, 'shipping_address_1', $address['address_1']);
        update_user_meta($user_id, 'shipping_address_2', $address['address_2']);
        update_user_meta($user_id, 'shipping_city', $address['city']);
        update_user_meta($user_id, 'shipping_state', $address['state']);    
        update_user_meta($user_id, 'shipping_postcode', $address['postcode']);
        update_user_meta($user_id, 'shipping_country', $address['country']);
        update_user_meta($user_id, 'shipping_phone', $address['phone']);
    }

    public function update_addresses(WP_REST_Request $request) {
        $user_id = get_current_user_id();

        // if (!$user_id) {
        //     return new WP_Error(
        //         'not_logged_in',
        //         'Authentication required.',
        //         ['status' => 401]
        //     );
        // }

        $customer = new WC_Customer($user_id);
        $data = $request->get_json_params();

        if (!empty($data['billing'])) {
            foreach ($data['billing'] as $key => $value) {
                $customer->{"set_billing_$key"}(sanitize_text_field($value));
            }
        }

        if (!empty($data['shipping'])) {
            foreach ($data['shipping'] as $key => $value) {
                $customer->{"set_shipping_$key"}(sanitize_text_field($value));
            }
        }

        $customer->save();

        return [
            'message' => 'Address updated successfully.',
            'billing' => $customer->get_billing(),
            'shipping' => $customer->get_shipping()
        ];
    }


    public function get_addresses() {
    $user_id = get_current_user_id();
    $saved = $this->get_saved_addresses($user_id);
    $user = wp_get_current_user();
     // Sidebar Response
   $sidebar_menu = [];
    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        $sidebar_menu[] = [
            'title'    => $label,
            'slug'     => $endpoint,
            'endpoint' => wc_get_account_endpoint_url($endpoint),
            'icon' => $this->get_menu_icon($endpoint)
        ];
    }


    // WooCommerce Billing Address
    $billing = [
        'type' => 'Billing',
        'name' => trim(
            get_user_meta($user_id, 'billing_first_name', true) . ' ' .
            get_user_meta($user_id, 'billing_last_name', true)
        ),
        'phone' => get_user_meta($user_id, 'billing_phone', true),
        'address_1' => get_user_meta($user_id, 'billing_address_1', true),
        'address_2' => get_user_meta($user_id, 'billing_address_2', true),
        'city' => get_user_meta($user_id, 'billing_city', true),
        'state' => get_user_meta($user_id, 'billing_state', true),
        'postcode' => get_user_meta($user_id, 'billing_postcode', true),
        'country' => get_user_meta($user_id, 'billing_country', true)
    ];

    // WooCommerce Shipping Address
    $shipping = [
        'type' => 'Shipping',
        'name' => trim(
            get_user_meta($user_id, 'shipping_first_name', true) . ' ' .
            get_user_meta($user_id, 'shipping_last_name', true)
        ),
        'phone' => get_user_meta($user_id, 'billing_phone', true),
        'address_1' => get_user_meta($user_id, 'shipping_address_1', true),
        'address_2' => get_user_meta($user_id, 'shipping_address_2', true),
        'city' => get_user_meta($user_id, 'shipping_city', true),
        'state' => get_user_meta($user_id, 'shipping_state', true),
        'postcode' => get_user_meta($user_id, 'shipping_postcode', true),
        'country' => get_user_meta($user_id, 'shipping_country', true)
    ];

    return [
        'sidebar' => $sidebar_menu,
        'billing' => $billing,
        'shipping' => $shipping,
        'saved_addresses' => array_values($saved)
    ];
}




    public function add_address(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $addresses = $this->get_saved_addresses($user_id);

        $new = [
            'id'         => time(),
            'type'       => $request->get_param('type'),
            'name'       => $request->get_param('name'),
            'phone'      => $request->get_param('phone'),
            'address_1'  => $request->get_param('address_1'),
            'address_2'  => $request->get_param('address_2'),
            'city'       => $request->get_param('city'),
            'state'      => $request->get_param('state'),
            'postcode'   => $request->get_param('postcode'),
            'country'    => $request->get_param('country'),
            'delivery_date' => $request->get_param('delivery_date'),
            'cod_available'=> (bool)$request->get_param('cod_available'),
            'is_default' => false
        ];

        $addresses[] = $new;
        $this->save_addresses($user_id, $addresses);

        return ['message' => 'Address added', 'id' => $new['id']];
    }

function get_custom_account_data() {

    $user = wp_get_current_user();

    // ---- Render template for extraction ----
    ob_start();
    wc_get_template('myaccount/dashboard.php');
    $html = ob_get_clean();


    // ---- Extract welcome title ----
    preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $html, $title_match);
    $welcome_title = strip_tags($title_match[1] ?? '');


    // ---- Extract welcome message ----
    preg_match('/<p[^>]*>(.*?)<\/p>/s', $html, $msg_match);
    $welcome_message = strip_tags($msg_match[1] ?? '');


    // ---- Extract dashboard icons + titles ----
 preg_match_all(
    '/<a[^>]*href="([^"]*)"[^>]*>.*?<img[^>]*src="([^"]+)"[^>]*>.*?<span[^>]*>(.*?)<\/span>.*?<\/a>/s',
    $html,
    $button_matches,
    PREG_SET_ORDER
);

    $buttons = array();
    foreach ($button_matches as $match) {
        $buttons[] = array(
            'endpoint' => html_entity_decode($match[1]),
            'icon'     => html_entity_decode($match[2]),
            'title'    => trim(strip_tags($match[3])),
        );
    }


    // ---- Profile Data ----
    $profile = array(
        'name'   => $user->display_name,
        'email'  => $user->user_email,
        'avatar' => get_avatar_url($user->ID, ['size' => 120]),
    );


    // ---- Dynamic Sidebar Menu ----
    $sidebar_menu = array();
    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        $sidebar_menu[] = array(
            'title'    => $label,
            'endpoint' => wc_get_account_endpoint_url($endpoint),
            'slug'     => $endpoint
        );
    }


    // ---- Final JSON Response ----
    return array(
        'welcome_title'     => trim($welcome_title),
        'welcome_message'   => trim($welcome_message),
        'profile'           => $profile,
        'sidebar_menu'      => $sidebar_menu,
        'dashboard_buttons' => $buttons
    );
}

    /* --------------------------------------------------------
        1️⃣ Full Account Overview
    ---------------------------------------------------------*/
    public function get_account_overview(WP_REST_Request $request) {
        return [
            'details'   => $this->get_account_details($request),
            'addresses' => $this->get_addresses($request),
            'downloads' => $this->get_downloads($request),
            'orders'    => $this->get_orders($request),
        ];
    }

    private function get_menu_icon($endpoint) {
        $icons = [
            'dashboard'       => 'dashicons-admin-home',
            'orders'          => 'dashicons-cart',
            'downloads'       => 'dashicons-download',
            'edit-address'    => 'dashicons-location-alt',
            'edit-account'    => 'dashicons-admin-users',
            'customer-logout' => 'dashicons-external'
        ];

        return $icons[$endpoint] ?? 'dashicons-admin-generic';
    }


    /* --------------------------------------------------------
        2️⃣ Orders
    ---------------------------------------------------------*/
   public function get_orders(WP_REST_Request $request) {

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('no_auth', 'User not authenticated', ['status' => 401]);
    }

    /** Sidebar Profile */
    $user = get_user_by('id', $user_id);
    $profile = [
        'name'   => $user->display_name,
        'email'  => $user->user_email,
        'avatar' => get_avatar_url($user_id, ['size' => 120]),
    ];

    /** Sidebar Menu */
    $sidebar_menu = [];
    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        $sidebar_menu[] = [
            'title'    => $label,
            'slug'     => $endpoint,
            'endpoint' => wc_get_account_endpoint_url($endpoint),
            'icon' => $this->get_menu_icon($endpoint)
        ];
    }

    /** Orders List */
    $orders = wc_get_orders([
        'customer_id' => $user_id,
        'limit'       => -1,
        'orderby'     => 'date',
        'order'       => 'DESC'
    ]);

    $orders_list = [];
    foreach ($orders as $order) {

        $orders_list[] = [
            'id'           => $order->get_id(),
            'order_number' => '#' . $order->get_order_number(),
            'date'         => wc_format_datetime($order->get_date_created(), get_option('date_format')),
            'status'       => wc_get_order_status_name($order->get_status()),
            'total'        => $order->get_formatted_order_total(),
            'actions' => [
                [
                    'name' => 'View',
                    'icon' => 'eye',
                    'url'  => $order->get_view_order_url()
                ]
            ],
            'line_items' => array_map(function($item) {
                return [
                    'name'     => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'subtotal' => wc_price($item->get_subtotal()),
                    'total'    => wc_price($item->get_total()),
                ];
            }, $order->get_items())
        ];
    }

    /** Final API Response */
    return [
        'profile'      => $profile,
        'sidebar_menu' => $sidebar_menu,
        'message' => styluza_parse_message(get_theme_mod('styluza_orders_message'), $user_id),
        'orders'       => $orders_list
    ];
}

function get_single_order_details(WP_REST_Request $request) {
    $user_id  = get_current_user_id();
    $order_id = absint($request['id']);
    $order    = wc_get_order($order_id);

    if (!$order) {
        return new WP_Error('no_order', 'Order not found.', ['status' => 404]);
    }

    // Secure: Prevent customers from viewing others’ orders
    if ($order->get_user_id() !== $user_id) {
        return new WP_Error('forbidden', 'You cannot view this order.', ['status' => 403]);
    }

    /* --- Sidebar Profile --- */
    $user = get_userdata($user_id);
    $profile = [
        'name'   => $user->display_name,
        'email'  => $user->user_email,
        'avatar' => get_avatar_url($user_id, ['size' => 200]),
    ];

    /* --- Sidebar Menu --- */
    $sidebar_menu = [];
    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        $sidebar_menu[] = [
            'title'    => $label,
            'slug'     => $endpoint,
            'endpoint' => wc_get_account_endpoint_url($endpoint)
        ];
    }

    /* --- Dynamic Order View Message --- */
    $intro_message = styluza_parse_message(get_theme_mod('styluza_order_view_message'), $user_id);

    /* --- Order Summary --- */
    $order_summary = [
        'order_number' => '#' . $order->get_order_number(),
        'date'         => wc_format_datetime($order->get_date_created(), get_option('date_format')),
        'status'       => wc_get_order_status_name($order->get_status()),
    ];

    /* --- Order Items --- */
    $items = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $items[] = [
            'name'        => $item->get_name(),
            'quantity'    => $item->get_quantity(),
            'price'       => wc_price($item->get_total()),
            'image'       => wp_get_attachment_image_url($product->get_image_id(), 'medium'),
            'attributes'  => wc_display_item_meta($item, ['echo' => false]),
        ];
    }

    // Count total quantity of items in order
$item_count = array_sum(array_map(function($i){
    return $i['quantity'];
}, $items));

    /* --- Price Details --- */
    $price_details = [
        'title' => 'Price Details (' . $item_count . ' Items)',
        'rows'  => [
            [
                'label' => 'Total Items Prices',
                'value' => strip_tags(html_entity_decode(wc_price($order->get_subtotal())))
            ],
            [
                'label' => 'Coupon Discount',
                'value' => strip_tags(html_entity_decode(wc_price($order->get_discount_total()))),
                'class' => 'discount'
            ],
            [
                'label' => 'Delivery Fee (Scheduled)',
                'value' => $order->get_shipping_total() == 0
                    ? 'FREE'
                    : strip_tags(html_entity_decode(wc_price($order->get_shipping_total()))),
                'class' => 'shipping'
            ],
            [
                'label' => 'Total',
                'value' => strip_tags(html_entity_decode(wc_price($order->get_total()))),
                'class' => 'total bold green'
            ],
        ]
    ];

    /* --- Billing Address --- */
    $raw_address = $order->get_formatted_billing_address();

    // Remove <br/> and convert to readable format
    $clean_address = trim(str_replace('<br/>', ', ', $raw_address));

    $billing = [
        'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'address' => $clean_address,
        'phone'   => $order->get_billing_phone(),
        'email'   => $order->get_billing_email(),
    ];

    return [
        'profile'       => $profile,
        'sidebar_menu'  => $sidebar_menu,
        'message'       => $intro_message,
        'order_summary' => $order_summary,
        'item_count' => $item_count,
        'items'         => $items,
        'price_details' => $price_details,
        'billing'       => $billing,
    ];
}




    /* --------------------------------------------------------
        3️⃣ Downloads
    ---------------------------------------------------------*/
 public function get_downloads(WP_REST_Request $request) {

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User authentication required', ['status' => 401]);
    }

    $user = wp_get_current_user();

    // ---- Profile Data ----
    $profile = [
        'name'   => $user->display_name,
        'email'  => $user->user_email,
        'avatar' => get_avatar_url($user->ID, ['size' => 120]),
    ];

    // ---- Sidebar Menu ----
    $sidebar_menu = [];
    foreach (wc_get_account_menu_items() as $endpoint => $label) {
        $sidebar_menu[] = [
            'title' => $label,
            'url'   => wc_get_account_endpoint_url($endpoint),
            'slug'  => $endpoint,
        ];
    }

    // ---- Downloads ----
    $downloads = wc_get_customer_available_downloads($user_id);
    $download_data = [];

    foreach ($downloads as $download) {
        $download_data[] = [
            'product_name'   => $download['product_name'],
            'download_url'   => $download['download_url'],
            'remaining'      => $download['downloads_remaining'],
            'order_id'       => isset($download['order_id']) ? $download['order_id'] : null,
            'access_expires' => $download['access_expires'] ?: 'Never',
        ];
    }

    // ---- Final API Response ----
    return [
        'profile'      => $profile,
        'sidebar_menu' => $sidebar_menu,
        'downloads'    => $download_data,
    ];
}



    /* --------------------------------------------------------
        4️⃣ Billing + Shipping Address
    ---------------------------------------------------------*/
  

    /* --------------------------------------------------------
        5️⃣ Account Details (Name, Email, Username)
    ---------------------------------------------------------*/
    public function get_account_details(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        $customer = new WC_Customer($user_id);
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
            'phone'        => $customer->get_billing_phone() ?: null,
        ];
    }


    /* --------------------------------------------------------
        🔐 Permission Check (Uses your existing method)
    ---------------------------------------------------------*/
    public function check_permission($request = null) {
        // First, respect any existing WP authentication (cookies, basic, bearer via determine_current_user)
        $user_id = get_current_user_id();

        // If not authenticated yet, try to parse a Bearer token from the Authorization header
        if (!$user_id && $request) {
            $auth_header = $request->get_header('authorization');
            if ($auth_header) {
                // Bearer token
                if (preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
                    $token = $m[1];
                    $users = get_users([
                        'meta_key' => '_api_token',
                        'meta_value' => $token,
                        'number' => 1,
                        'count_total' => false,
                    ]);
                    if (!empty($users)) {
                        wp_set_current_user($users[0]->ID);
                        $user_id = $users[0]->ID;
                    }
                }

                // Basic auth fallback (if Bearer not present)
                if (!$user_id && preg_match('/Basic\s+(.+)$/i', $auth_header, $matches)) {
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
        }

        // Allow any authenticated user (customers) to access account endpoints
        if ($user_id && $user_id > 0) {
            return true;
        }

        return false;
	}

    public function update_user_account(WP_REST_Request $request) {

    $user = wp_get_current_user();
    $user_id = $user->ID;

    $params = $request->get_json_params();

    /* Update Profile Fields */
    if (!empty($params['first_name'])) {
        update_user_meta($user_id, 'first_name', sanitize_text_field($params['first_name']));
    }

    if (!empty($params['last_name'])) {
        update_user_meta($user_id, 'last_name', sanitize_text_field($params['last_name']));
    }

    if (!empty($params['mobile'])) {
        update_user_meta($user_id, 'billing_phone', sanitize_text_field($params['mobile']));
    }

    if (!empty($params['email'])) {
        $email = sanitize_email($params['email']);

        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email format.', ['status' => 400]);
        }

        wp_update_user([
            'ID' => $user_id,
            'user_email' => $email
        ]);
    }

    /* Password Update (Optional) */
    $current = $params['current_password'] ?? '';
    $new = $params['new_password'] ?? '';
    $confirm = $params['confirm_password'] ?? '';

    if (!empty($new) || !empty($confirm)) {

        if (empty($current)) {
            return new WP_Error('no_current_password', 'Current password required.', ['status' => 400]);
        }

        if (!wp_check_password($current, $user->user_pass, $user_id)) {
            return new WP_Error('incorrect_password', 'Current password is incorrect.', ['status' => 400]);
        }

        if ($new !== $confirm) {
            return new WP_Error('password_mismatch', 'Passwords do not match.', ['status' => 400]);
        }

        wp_set_password($new, $user_id);
    }

    return [
        'success' => true,
        'message' => 'Account updated successfully.'
    ];
}

}
