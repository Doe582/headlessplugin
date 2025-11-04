<?php

class RESTBridge_Coupons_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons', [
			'methods' => 'GET',
			'callback' => [$this, 'get_coupons'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons', [
			'methods' => 'POST',
			'callback' => [$this, 'create_coupon'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_coupon'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_coupon'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_coupon'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/coupons/validate', [
			'methods' => 'POST',
			'callback' => [$this, 'validate_coupon'],
			'permission_callback' => '__return_true',
		]);
	}

	public function get_coupons(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		
		$args = [
			'post_type' => 'shop_coupon',
			'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
			'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'paged' => isset($params['page']) ? (int) $params['page'] : 1,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['search'])) {
			$args['s'] = sanitize_text_field($params['search']);
		}

		$query = new WP_Query($args);
		$coupons = [];

		while ($query->have_posts()) {
			$query->the_post();
			$coupon = new WC_Coupon(get_the_ID());
			if ($coupon) {
				$coupons[] = $this->format_coupon($coupon);
			}
		}
		wp_reset_postdata();

		return rest_ensure_response([
			'coupons' => $coupons,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		]);
	}

	public function get_coupon(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$coupon_id = (int) $request['id'];
		$coupon = new WC_Coupon($coupon_id);

		if (!$coupon->get_id()) {
			return new WP_Error('coupon_not_found', 'Coupon not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_coupon($coupon));
	}

	public function create_coupon(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		$coupon = new WC_Coupon();
		
		if (isset($params['code'])) {
			$coupon->set_code(sanitize_text_field($params['code']));
		} else {
			return new WP_Error('missing_code', 'Coupon code is required', ['status' => 400]);
		}

		if (isset($params['discount_type'])) {
			$coupon->set_discount_type(sanitize_text_field($params['discount_type']));
		}

		if (isset($params['amount'])) {
			$coupon->set_amount((float) $params['amount']);
		}

		if (isset($params['description'])) {
			$coupon->set_description(sanitize_textarea_field($params['description']));
		}

		if (isset($params['individual_use'])) {
			$coupon->set_individual_use((bool) $params['individual_use']);
		}

		if (isset($params['usage_limit'])) {
			$coupon->set_usage_limit((int) $params['usage_limit']);
		}

		if (isset($params['usage_limit_per_user'])) {
			$coupon->set_usage_limit_per_user((int) $params['usage_limit_per_user']);
		}

		if (isset($params['limit_usage_to_x_items'])) {
			$coupon->set_limit_usage_to_x_items((int) $params['limit_usage_to_x_items']);
		}

		if (isset($params['expiry_date'])) {
			$coupon->set_date_expires(strtotime($params['expiry_date']));
		}

		if (isset($params['minimum_amount'])) {
			$coupon->set_minimum_amount((float) $params['minimum_amount']);
		}

		if (isset($params['maximum_amount'])) {
			$coupon->set_maximum_amount((float) $params['maximum_amount']);
		}

		if (isset($params['free_shipping'])) {
			$coupon->set_free_shipping((bool) $params['free_shipping']);
		}

		if (isset($params['exclude_sale_items'])) {
			$coupon->set_exclude_sale_items((bool) $params['exclude_sale_items']);
		}

		if (isset($params['product_ids']) && is_array($params['product_ids'])) {
			$coupon->set_product_ids(array_map('intval', $params['product_ids']));
		}

		if (isset($params['excluded_product_ids']) && is_array($params['excluded_product_ids'])) {
			$coupon->set_excluded_product_ids(array_map('intval', $params['excluded_product_ids']));
		}

		if (isset($params['product_categories']) && is_array($params['product_categories'])) {
			$coupon->set_product_categories(array_map('intval', $params['product_categories']));
		}

		if (isset($params['excluded_product_categories']) && is_array($params['excluded_product_categories'])) {
			$coupon->set_excluded_product_categories(array_map('intval', $params['excluded_product_categories']));
		}

		$coupon_id = $coupon->save();

		if (!$coupon_id) {
			return new WP_Error('coupon_failed', 'Failed to create coupon', ['status' => 500]);
		}

		return rest_ensure_response($this->format_coupon(new WC_Coupon($coupon_id)), 201);
	}

	public function update_coupon(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$coupon_id = (int) $request['id'];
		$params = $request->get_json_params();

		$coupon = new WC_Coupon($coupon_id);
		
		if (!$coupon->get_id()) {
			return new WP_Error('coupon_not_found', 'Coupon not found', ['status' => 404]);
		}

		if (isset($params['code'])) {
			$coupon->set_code(sanitize_text_field($params['code']));
		}

		if (isset($params['discount_type'])) {
			$coupon->set_discount_type(sanitize_text_field($params['discount_type']));
		}

		if (isset($params['amount'])) {
			$coupon->set_amount((float) $params['amount']);
		}

		if (isset($params['description'])) {
			$coupon->set_description(sanitize_textarea_field($params['description']));
		}

		if (isset($params['individual_use'])) {
			$coupon->set_individual_use((bool) $params['individual_use']);
		}

		if (isset($params['usage_limit'])) {
			$coupon->set_usage_limit((int) $params['usage_limit']);
		}

		if (isset($params['usage_limit_per_user'])) {
			$coupon->set_usage_limit_per_user((int) $params['usage_limit_per_user']);
		}

		if (isset($params['limit_usage_to_x_items'])) {
			$coupon->set_limit_usage_to_x_items((int) $params['limit_usage_to_x_items']);
		}

		if (isset($params['expiry_date'])) {
			$coupon->set_date_expires(strtotime($params['expiry_date']));
		}

		if (isset($params['minimum_amount'])) {
			$coupon->set_minimum_amount((float) $params['minimum_amount']);
		}

		if (isset($params['maximum_amount'])) {
			$coupon->set_maximum_amount((float) $params['maximum_amount']);
		}

		if (isset($params['free_shipping'])) {
			$coupon->set_free_shipping((bool) $params['free_shipping']);
		}

		if (isset($params['exclude_sale_items'])) {
			$coupon->set_exclude_sale_items((bool) $params['exclude_sale_items']);
		}

		if (isset($params['product_ids']) && is_array($params['product_ids'])) {
			$coupon->set_product_ids(array_map('intval', $params['product_ids']));
		}

		if (isset($params['excluded_product_ids']) && is_array($params['excluded_product_ids'])) {
			$coupon->set_excluded_product_ids(array_map('intval', $params['excluded_product_ids']));
		}

		if (isset($params['product_categories']) && is_array($params['product_categories'])) {
			$coupon->set_product_categories(array_map('intval', $params['product_categories']));
		}

		if (isset($params['excluded_product_categories']) && is_array($params['excluded_product_categories'])) {
			$coupon->set_excluded_product_categories(array_map('intval', $params['excluded_product_categories']));
		}

		$coupon->save();

		return rest_ensure_response($this->format_coupon($coupon));
	}

	public function delete_coupon(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$coupon_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$coupon = new WC_Coupon($coupon_id);
		if (!$coupon->get_id()) {
			return new WP_Error('coupon_not_found', 'Coupon not found', ['status' => 404]);
		}

		$result = wp_delete_post($coupon_id, $force);

		if (!$result) {
			return new WP_Error('delete_failed', 'Failed to delete coupon', ['status' => 500]);
		}

		return rest_ensure_response(['deleted' => true, 'id' => $coupon_id]);
	}

	public function validate_coupon(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();
		$code = isset($params['code']) ? sanitize_text_field($params['code']) : '';

		if (!$code) {
			return new WP_Error('missing_code', 'Coupon code is required', ['status' => 400]);
		}

		$coupon = new WC_Coupon($code);
		
		if (!$coupon->get_id()) {
			return new WP_Error('invalid_coupon', 'Invalid coupon code', ['status' => 404]);
		}

		$valid = $coupon->is_valid();

		return rest_ensure_response([
			'valid' => $valid,
			'code' => $coupon->get_code(),
			'message' => $valid ? 'Coupon is valid' : 'Coupon is not valid',
			'discount_type' => $coupon->get_discount_type(),
			'amount' => $coupon->get_amount(),
			'coupon' => $valid ? $this->format_coupon($coupon) : null,
		]);
	}

	private function format_coupon($coupon) {
		return [
			'id' => $coupon->get_id(),
			'code' => $coupon->get_code(),
			'amount' => $coupon->get_amount(),
			'discount_type' => $coupon->get_discount_type(),
			'description' => $coupon->get_description(),
			'date_created' => $coupon->get_date_created() ? $coupon->get_date_created()->date('Y-m-d H:i:s') : null,
			'date_modified' => $coupon->get_date_modified() ? $coupon->get_date_modified()->date('Y-m-d H:i:s') : null,
			'date_expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->date('Y-m-d H:i:s') : null,
			'usage_count' => $coupon->get_usage_count(),
			'usage_limit' => $coupon->get_usage_limit(),
			'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
			'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
			'individual_use' => $coupon->get_individual_use(),
			'free_shipping' => $coupon->get_free_shipping(),
			'exclude_sale_items' => $coupon->get_exclude_sale_items(),
			'minimum_amount' => $coupon->get_minimum_amount(),
			'maximum_amount' => $coupon->get_maximum_amount(),
			'product_ids' => $coupon->get_product_ids(),
			'excluded_product_ids' => $coupon->get_excluded_product_ids(),
			'product_categories' => $coupon->get_product_categories(),
			'excluded_product_categories' => $coupon->get_excluded_product_categories(),
			'email_restrictions' => $coupon->get_email_restrictions(),
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

