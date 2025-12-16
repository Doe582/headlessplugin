<?php

class RESTBridge_Customers_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers', [
			'methods' => 'GET',
			'callback' => [$this, 'get_customers'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers', [
			'methods' => 'POST',
			'callback' => [$this, 'create_customer'],
			'permission_callback' => '__return_true',
		]);

		// Dedicated registration endpoint
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers/register', [
			'methods' => 'POST',
			'callback' => [$this, 'register_customer'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_customer'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_customer'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_customer'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/customers/(?P<id>\d+)/orders', [
			'methods' => 'GET',
			'callback' => [$this, 'get_customer_orders'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_customers(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$current_user_id = get_current_user_id();

		// Check if user is authenticated
		if (!$current_user_id) {
			return new WP_Error('rest_not_authenticated', 'Authentication required', ['status' => 401]);
		}

		// Check if current user is admin
		$is_admin = $this->is_admin_user();

		$params = $request->get_query_params();
		
		$args = [
			'number' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'offset' => isset($params['page']) ? ((int) $params['page'] - 1) * (int) $params['per_page'] : 0,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'registered',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['search'])) {
			$args['search'] = '*' . sanitize_text_field($params['search']) . '*';
		}

		if (isset($params['role'])) {
			$args['role'] = sanitize_text_field($params['role']);
		} else {
			$args['role'] = 'customer';
		}

		// If not admin, user can only view their own data
		if (!$is_admin) {
			$args['include'] = [$current_user_id];
		}

		$users = get_users($args);
		$customers = [];

		foreach ($users as $user) {
			// Double check: if not admin, only include current user's data
			if (!$is_admin && $user->ID !== $current_user_id) {
				continue;
			}

			$customer = new WC_Customer($user->ID);
			if ($customer->get_id()) {
				$customers[] = $this->format_customer($customer);
			}
		}

		return rest_ensure_response([
			'customers' => $customers,
			'total' => $is_admin ? (count_users()['avail_roles']['customer'] ?? 0) : 1,
		]);
	}

	public function get_customer(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$customer_id = (int) $request['id'];
		$current_user_id = get_current_user_id();

		// Check if user is authenticated
		if (!$current_user_id) {
			return new WP_Error('rest_not_authenticated', 'Authentication required', ['status' => 401]);
		}

		// Check if current user is admin
		$is_admin = $this->is_admin_user();

		// If not admin, user can only view their own customer data
		if (!$is_admin && $current_user_id !== $customer_id) {
			return new WP_Error(
				'rest_cannot_access',
				'Sorry, you are not allowed to view this customer. You can only view your own data.',
				['status' => 403]
			);
		}

		$customer = new WC_Customer($customer_id);

		if (!$customer->get_id()) {
			return new WP_Error('customer_not_found', 'Customer not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_customer($customer));
	}

	public function create_customer(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		if (!isset($params['email'])) {
			return new WP_Error('missing_email', 'Email is required', ['status' => 400]);
		}

		$customer = new WC_Customer();

		if (isset($params['email'])) {
			$customer->set_email(sanitize_email($params['email']));
		}

		if (isset($params['username'])) {
			$customer->set_username(sanitize_user($params['username']));
		}

		if (isset($params['password'])) {
			$customer->set_password($params['password']);
		}

		if (isset($params['first_name'])) {
			$customer->set_first_name(sanitize_text_field($params['first_name']));
		}

		if (isset($params['last_name'])) {
			$customer->set_last_name(sanitize_text_field($params['last_name']));
		}

		if (isset($params['billing_first_name'])) {
			$customer->set_billing_first_name(sanitize_text_field($params['billing_first_name']));
		}

		if (isset($params['billing_last_name'])) {
			$customer->set_billing_last_name(sanitize_text_field($params['billing_last_name']));
		}

		if (isset($params['billing_company'])) {
			$customer->set_billing_company(sanitize_text_field($params['billing_company']));
		}

		if (isset($params['billing_address_1'])) {
			$customer->set_billing_address_1(sanitize_text_field($params['billing_address_1']));
		}

		if (isset($params['billing_address_2'])) {
			$customer->set_billing_address_2(sanitize_text_field($params['billing_address_2']));
		}

		if (isset($params['billing_city'])) {
			$customer->set_billing_city(sanitize_text_field($params['billing_city']));
		}

		if (isset($params['billing_state'])) {
			$customer->set_billing_state(sanitize_text_field($params['billing_state']));
		}

		if (isset($params['billing_postcode'])) {
			$customer->set_billing_postcode(sanitize_text_field($params['billing_postcode']));
		}

		if (isset($params['billing_country'])) {
			$customer->set_billing_country(sanitize_text_field($params['billing_country']));
		}

		if (isset($params['billing_email'])) {
			$customer->set_billing_email(sanitize_email($params['billing_email']));
		}

		if (isset($params['billing_phone'])) {
			$customer->set_billing_phone(sanitize_text_field($params['billing_phone']));
		}

		if (isset($params['shipping_first_name'])) {
			$customer->set_shipping_first_name(sanitize_text_field($params['shipping_first_name']));
		}

		if (isset($params['shipping_last_name'])) {
			$customer->set_shipping_last_name(sanitize_text_field($params['shipping_last_name']));
		}

		if (isset($params['shipping_company'])) {
			$customer->set_shipping_company(sanitize_text_field($params['shipping_company']));
		}

		if (isset($params['shipping_address_1'])) {
			$customer->set_shipping_address_1(sanitize_text_field($params['shipping_address_1']));
		}

		if (isset($params['shipping_address_2'])) {
			$customer->set_shipping_address_2(sanitize_text_field($params['shipping_address_2']));
		}

		if (isset($params['shipping_city'])) {
			$customer->set_shipping_city(sanitize_text_field($params['shipping_city']));
		}

		if (isset($params['shipping_state'])) {
			$customer->set_shipping_state(sanitize_text_field($params['shipping_state']));
		}

		if (isset($params['shipping_postcode'])) {
			$customer->set_shipping_postcode(sanitize_text_field($params['shipping_postcode']));
		}

		if (isset($params['shipping_country'])) {
			$customer->set_shipping_country(sanitize_text_field($params['shipping_country']));
		}

		$customer_id = $customer->save();

		if (!$customer_id) {
			return new WP_Error('customer_failed', 'Failed to create customer', ['status' => 500]);
		}

		// 🔹 Save custom meta
		if (isset($params['custom_meta']) && is_array($params['custom_meta'])) {
			foreach ($params['custom_meta'] as $meta_key => $meta_value) {
				update_user_meta(
					$customer_id,
					sanitize_key($meta_key),
					sanitize_text_field($meta_value)
				);
			}
		}

		return rest_ensure_response($this->format_customer(new WC_Customer($customer_id)), 201);
	}

	/**
	 * Register a new customer (dedicated endpoint)
	 * POST /wp-json/restbridge/v1/customers/register
	 * 
	 * Body:
	 * {
	 *   "email": "user@example.com",
	 *   "username": "username",
	 *   "password": "password",
	 *   "first_name": "John",
	 *   "last_name": "Doe"
	 * }
	 */
	public function register_customer(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		// Validate required fields
		if (empty($params['email'])) {
			return new WP_Error('missing_email', 'Email is required', ['status' => 400]);
		}

		if (empty($params['password'])) {
			return new WP_Error('missing_password', 'Password is required', ['status' => 400]);
		}

		$email = sanitize_email($params['email']);
		$password = $params['password'];
		$username = isset($params['username']) ? sanitize_user($params['username']) : '';
		$first_name = isset($params['first_name']) ? sanitize_text_field($params['first_name']) : '';
		$last_name = isset($params['last_name']) ? sanitize_text_field($params['last_name']) : '';
		$billing_email = isset($params['billing_email']) ? sanitize_email($params['billing_email']) : '';
		$billing_phone      = isset($params['billing_phone']) ? sanitize_text_field($params['billing_phone']) : '';
		$billing_address_1  = isset($params['billing_address_1']) ? sanitize_text_field($params['billing_address_1']) : '';
		$billing_address_2  = isset($params['billing_address_2']) ? sanitize_text_field($params['billing_address_2']) : '';
		$billing_city       = isset($params['billing_city']) ? sanitize_text_field($params['billing_city']) : '';
		$billing_state      = isset($params['billing_state']) ? sanitize_text_field($params['billing_state']) : '';
		$billing_postcode   = isset($params['billing_postcode']) ? sanitize_text_field($params['billing_postcode']) : '';
		$billing_country    = isset($params['billing_country']) ? sanitize_text_field($params['billing_country']) : '';


		// Validate email
		if (!is_email($email)) {
			return new WP_Error('invalid_email', 'Please provide a valid email address', ['status' => 400]);
		}

		// Check if email already exists
		if (email_exists($email)) {
			return new WP_Error('email_exists', 'This email is already registered', ['status' => 400]);
		}

		// Generate username if not provided
		if (empty($username)) {
			$username = explode('@', $email)[0];
			$base_username = $username;
			$counter = 1;

			while (username_exists($username)) {
				$username = $base_username . $counter;
				$counter++;
			}
		} else {
			// Check if username already exists
			if (username_exists($username)) {
				return new WP_Error('username_exists', 'This username is already taken', ['status' => 400]);
			}
		}

		// Validate password strength
		if (strlen($password) < 6) {
			return new WP_Error('weak_password', 'Password must be at least 6 characters long', ['status' => 400]);
		}

		// Create WordPress user
		$user_data = [
			'user_login' => $username,
			'user_email' => $email,
			'user_pass' => $password,
			'first_name' => $first_name,
			'last_name' => $last_name,
			'role' => 'customer',
		];

		$user_id = wp_insert_user($user_data);

		if (is_wp_error($user_id)) {
			return new WP_Error('registration_failed', $user_id->get_error_message(), ['status' => 500]);
		}

		// Create WooCommerce customer
		$customer = new WC_Customer($user_id);

		$customer->set_email($email);
		$customer->set_first_name($first_name);
		$customer->set_last_name($last_name);

		// Billing fields
		if ($billing_email) {
			$customer->set_billing_email($billing_email);
		}
		if ($billing_phone) {
			$customer->set_billing_phone($billing_phone);
		}
		if ($billing_address_1) {
			$customer->set_billing_address_1($billing_address_1);
		}
		if ($billing_address_2) {
			$customer->set_billing_address_2($billing_address_2);
		}
		if ($billing_city) {
			$customer->set_billing_city($billing_city);
		}
		if ($billing_state) {
			$customer->set_billing_state($billing_state);
		}
		if ($billing_postcode) {
			$customer->set_billing_postcode($billing_postcode);
		}
		if ($billing_country) {
			$customer->set_billing_country($billing_country);
		}

		$customer->save();


		if (isset($params['custom_meta']) && is_array($params['custom_meta'])) {

			$blocked_meta_keys = [
				'user_pass',
				'user_login',
				'user_email',
				'wp_capabilities',
				'wp_user_level',
				'dismissed_wp_pointers',
				'session_tokens'
			];

			foreach ($params['custom_meta'] as $meta_key => $meta_value) {

				if (!is_string($meta_key)) {
					continue;
				}

				$meta_key = sanitize_key($meta_key);

				if (in_array($meta_key, $blocked_meta_keys, true)) {
					continue;
				}

				if (is_array($meta_value) || is_object($meta_value)) {
					update_user_meta($user_id, $meta_key, wp_json_encode($meta_value));
				} else {
					update_user_meta(
						$user_id,
						$meta_key,
						sanitize_text_field($meta_value)
					);
				}
			}
		}

		// Do registration action hook
		do_action('customer_register', $user_id);

		return rest_ensure_response([
			'message' => 'Customer registered successfully',
			'user_id' => $user_id,
			'email' => $email,
			'username' => $username,
			'first_name' => $first_name,
			'last_name' => $last_name,
		], 201);
	}

	public function update_customer(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$customer_id = (int) $request['id'];
		
		$current_user_id = get_current_user_id();

		// Check if user is authenticated
		if (!$current_user_id) {
			return new WP_Error('rest_not_authenticated', 'Authentication required', ['status' => 401]);
		}

		// Check if current user is admin
		$is_admin = current_user_can('manage_woocommerce') || current_user_can('administrator');

		// If not admin, user can only update their own customer data
		if (!$is_admin && $current_user_id !== $customer_id) {
			return new WP_Error(
				'rest_cannot_access',
				'Sorry, you are not allowed to update this customer. You can only update your own data.',
				['status' => 403]
			);
		}

		$params = $request->get_json_params();

		$customer = new WC_Customer($customer_id);
		
		if (!$customer->get_id()) {
			return new WP_Error('customer_not_found', 'Customer not found', ['status' => 404]);
		}

		if (isset($params['email'])) {
			$customer->set_email(sanitize_email($params['email']));
		}

		if (isset($params['first_name'])) {
			$customer->set_first_name(sanitize_text_field($params['first_name']));
		}

		if (isset($params['last_name'])) {
			$customer->set_last_name(sanitize_text_field($params['last_name']));
		}

		if (isset($params['billing_first_name'])) {
			$customer->set_billing_first_name(sanitize_text_field($params['billing_first_name']));
		}

		if (isset($params['billing_last_name'])) {
			$customer->set_billing_last_name(sanitize_text_field($params['billing_last_name']));
		}

		if (isset($params['billing_company'])) {
			$customer->set_billing_company(sanitize_text_field($params['billing_company']));
		}

		if (isset($params['billing_address_1'])) {
			$customer->set_billing_address_1(sanitize_text_field($params['billing_address_1']));
		}

		if (isset($params['billing_address_2'])) {
			$customer->set_billing_address_2(sanitize_text_field($params['billing_address_2']));
		}

		if (isset($params['billing_city'])) {
			$customer->set_billing_city(sanitize_text_field($params['billing_city']));
		}

		if (isset($params['billing_state'])) {
			$customer->set_billing_state(sanitize_text_field($params['billing_state']));
		}

		if (isset($params['billing_postcode'])) {
			$customer->set_billing_postcode(sanitize_text_field($params['billing_postcode']));
		}

		if (isset($params['billing_country'])) {
			$customer->set_billing_country(sanitize_text_field($params['billing_country']));
		}

		if (isset($params['billing_email'])) {
			$customer->set_billing_email(sanitize_email($params['billing_email']));
		}

		if (isset($params['billing_phone'])) {
			$customer->set_billing_phone(sanitize_text_field($params['billing_phone']));
		}

		if (isset($params['shipping_first_name'])) {
			$customer->set_shipping_first_name(sanitize_text_field($params['shipping_first_name']));
		}

		if (isset($params['shipping_last_name'])) {
			$customer->set_shipping_last_name(sanitize_text_field($params['shipping_last_name']));
		}

		if (isset($params['shipping_company'])) {
			$customer->set_shipping_company(sanitize_text_field($params['shipping_company']));
		}

		if (isset($params['shipping_address_1'])) {
			$customer->set_shipping_address_1(sanitize_text_field($params['shipping_address_1']));
		}

		if (isset($params['shipping_address_2'])) {
			$customer->set_shipping_address_2(sanitize_text_field($params['shipping_address_2']));
		}

		if (isset($params['shipping_city'])) {
			$customer->set_shipping_city(sanitize_text_field($params['shipping_city']));
		}

		if (isset($params['shipping_state'])) {
			$customer->set_shipping_state(sanitize_text_field($params['shipping_state']));
		}

		if (isset($params['shipping_postcode'])) {
			$customer->set_shipping_postcode(sanitize_text_field($params['shipping_postcode']));
		}

		if (isset($params['shipping_country'])) {
			$customer->set_shipping_country(sanitize_text_field($params['shipping_country']));
		}

		// if (isset($params['password'])) {
		// 	$customer->set_password($params['password']);
		// }

		$customer->save();

		return rest_ensure_response($this->format_customer($customer));
	}

	public function delete_customer(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$customer_id = (int) $request['id'];
		$current_user_id = get_current_user_id();

		// Check if user is authenticated
		if (!$current_user_id) {
			return new WP_Error('rest_not_authenticated', 'Authentication required', ['status' => 401]);
		}

		// Check if current user is admin
		$is_admin = $this->is_admin_user();

		// Only admins can delete customers (users cannot delete themselves via this endpoint)
		if (!$is_admin) {
			return new WP_Error(	
				'rest_cannot_access',
				'Sorry, you are not allowed to delete customers. Only administrators can delete customers.',
				['status' => 403]
			);
		}

		$reassign = isset($request['reassign']) ? (int) $request['reassign'] : null;

		$customer = new WC_Customer($customer_id);
		if (!$customer->get_id()) {
			return new WP_Error('customer_not_found', 'Customer not found', ['status' => 404]);
		}

		require_once(ABSPATH . 'wp-admin/includes/user.php');
		$result = wp_delete_user($customer_id, $reassign);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete customer', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $customer_id]);
	}

	public function get_customer_orders(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$customer_id = (int) $request['id'];
		$current_user_id = get_current_user_id();

		// Check if user is authenticated
		if (!$current_user_id) {
			return new WP_Error('rest_not_authenticated', 'Authentication required', ['status' => 401]);
		}

		// Check if current user is admin
		$is_admin = $this->is_admin_user();

		// If not admin, user can only view their own orders
		if (!$is_admin && $current_user_id !== $customer_id) {
			return new WP_Error(
				'rest_cannot_access',
				'Sorry, you are not allowed to view this customer\'s orders. You can only view your own orders.',
				['status' => 403]
			);
		}

		$params = $request->get_query_params();

		$customer = new WC_Customer($customer_id);
		if (!$customer->get_id()) {
			return new WP_Error('customer_not_found', 'Customer not found', ['status' => 404]);
		}

		$args = [
			'customer_id' => $customer_id,
			'limit' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'paged' => isset($params['page']) ? (int) $params['page'] : 1,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['status'])) {
			$args['status'] = sanitize_text_field($params['status']);
		}

		$orders = wc_get_orders($args);
		$formatted_orders = [];

		foreach ($orders as $order) {
			$formatted_orders[] = [
				'id' => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'status' => $order->get_status(),
				'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
				'total' => $order->get_total(),
				'currency' => $order->get_currency(),
				'payment_method' => $order->get_payment_method_title(),
			];
		}

		return rest_ensure_response([
			'orders' => $formatted_orders,
			'total' => count($formatted_orders),
		]);
	}

	private function format_customer($customer) {
		return [
			'id' => $customer->get_id(),
			'email' => $customer->get_email(),
			'username' => $customer->get_username(),
			'first_name' => $customer->get_first_name(),
			'last_name' => $customer->get_last_name(),
			'display_name' => $customer->get_display_name(),
			'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date('Y-m-d H:i:s') : null,
			'date_modified' => $customer->get_date_modified() ? $customer->get_date_modified()->date('Y-m-d H:i:s') : null,
			'billing' => [
				'first_name' => $customer->get_billing_first_name(),
				'last_name' => $customer->get_billing_last_name(),
				'company' => $customer->get_billing_company(),
				'address_1' => $customer->get_billing_address_1(),
				'address_2' => $customer->get_billing_address_2(),
				'city' => $customer->get_billing_city(),
				'state' => $customer->get_billing_state(),
				'postcode' => $customer->get_billing_postcode(),
				'country' => $customer->get_billing_country(),
				'email' => $customer->get_billing_email(),
				'phone' => $customer->get_billing_phone(),
			],
			'shipping' => [
				'first_name' => $customer->get_shipping_first_name(),
				'last_name' => $customer->get_shipping_last_name(),
				'company' => $customer->get_shipping_company(),
				'address_1' => $customer->get_shipping_address_1(),
				'address_2' => $customer->get_shipping_address_2(),
				'city' => $customer->get_shipping_city(),
				'state' => $customer->get_shipping_state(),
				'postcode' => $customer->get_shipping_postcode(),
				'country' => $customer->get_shipping_country(),
			],
			'orders_count' => $customer->get_order_count(),
			'total_spent' => $customer->get_total_spent(),
			'avatar_url' => get_avatar_url($customer->get_id()),
		];
	}

	public function check_permission($request = null) {
		$user_id = get_current_user_id();

		// 🔁 Basic Auth fallback (optional, keep if you need it)
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

		// ✅ ONLY check authentication here
		return (bool) $user_id;
	}
}


