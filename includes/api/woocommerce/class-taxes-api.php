<?php

class RESTBridge_Taxes_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes', [
			'methods' => 'GET',
			'callback' => [$this, 'get_tax_rates'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes', [
			'methods' => 'POST',
			'callback' => [$this, 'create_tax_rate'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_tax_rate'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_tax_rate'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_tax_rate'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/classes', [
			'methods' => 'GET',
			'callback' => [$this, 'get_tax_classes'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/classes', [
			'methods' => 'POST',
			'callback' => [$this, 'create_tax_class'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/taxes/classes/(?P<slug>[a-zA-Z0-9_-]+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_tax_class'],
			'permission_callback' => [$this, 'check_permission'],
		]);
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

	/**
	 * Get all tax rates
	 */
	public function get_tax_rates(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		global $wpdb;
		$params = $request->get_query_params();
		
		$table_name = $wpdb->prefix . 'woocommerce_tax_rates';
		
		$where = ['1=1'];
		$where_values = [];

		// Filter by country
		if (isset($params['country'])) {
			$where[] = 'tax_rate_country = %s';
			$where_values[] = sanitize_text_field($params['country']);
		}

		// Filter by state
		if (isset($params['state'])) {
			$where[] = 'tax_rate_state = %s';
			$where_values[] = sanitize_text_field($params['state']);
		}

		// Filter by class
		if (isset($params['class'])) {
			$where[] = 'tax_rate_class = %s';
			$where_values[] = sanitize_text_field($params['class']);
		}

		$where_clause = implode(' AND ', $where);
		
		$query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY tax_rate_order ASC, tax_rate_name ASC";
		
		if (!empty($where_values)) {
			$query = $wpdb->prepare($query, $where_values);
		}
		
		$tax_rates = $wpdb->get_results($query, ARRAY_A);

		$formatted_rates = [];
		foreach ($tax_rates as $rate) {
			$formatted_rates[] = $this->format_tax_rate($rate);
		}

		return rest_ensure_response([
			'tax_rates' => $formatted_rates,
			'total' => count($formatted_rates),
		]);
	}

	/**
	 * Get a single tax rate by ID
	 */
	public function get_tax_rate(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		global $wpdb;
		$tax_rate_id = (int) $request['id'];
		$table_name = $wpdb->prefix . 'woocommerce_tax_rates';

		$tax_rate = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE tax_rate_id = %d",
				$tax_rate_id
			),
			ARRAY_A
		);

		if (!$tax_rate) {
			return new WP_Error('tax_rate_not_found', 'Tax rate not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_tax_rate($tax_rate));
	}

	/**
	 * Create a new tax rate
	 */
	public function create_tax_rate(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		global $wpdb;
		$params = $request->get_json_params();
		$table_name = $wpdb->prefix . 'woocommerce_tax_rates';

		// Required fields
		if (empty($params['country'])) {
			return new WP_Error('missing_country', 'Country is required', ['status' => 400]);
		}

		if (!isset($params['rate']) || $params['rate'] === '') {
			return new WP_Error('missing_rate', 'Tax rate is required', ['status' => 400]);
		}

		$data = [
			'tax_rate_country' => strtoupper(sanitize_text_field($params['country'])),
			'tax_rate_state' => isset($params['state']) ? strtoupper(sanitize_text_field($params['state'])) : '',
			'tax_rate' => sanitize_text_field($params['rate']),
			'tax_rate_name' => isset($params['name']) ? sanitize_text_field($params['name']) : '',
			'tax_rate_priority' => isset($params['priority']) ? (int) $params['priority'] : 1,
			'tax_rate_compound' => isset($params['compound']) ? (int) $params['compound'] : 0,
			'tax_rate_shipping' => isset($params['shipping']) ? (int) $params['shipping'] : 1,
			'tax_rate_order' => isset($params['order']) ? (int) $params['order'] : 0,
			'tax_rate_class' => isset($params['class']) ? sanitize_text_field($params['class']) : '',
		];

		$inserted = $wpdb->insert($table_name, $data);

		if ($inserted === false) {
			return new WP_Error('create_failed', 'Failed to create tax rate', ['status' => 500]);
		}

		$tax_rate_id = $wpdb->insert_id;

		// Clear tax rate cache
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::invalidate_cache_group('taxes');
		}

		// Get the created tax rate
		$tax_rate = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE tax_rate_id = %d",
				$tax_rate_id
			),
			ARRAY_A
		);

		return rest_ensure_response([
			'success' => true,
			'message' => 'Tax rate created successfully',
			'tax_rate' => $this->format_tax_rate($tax_rate),
		]);
	}

	/**
	 * Update a tax rate
	 */
	public function update_tax_rate(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		global $wpdb;
		$tax_rate_id = (int) $request['id'];
		$params = $request->get_json_params();
		$table_name = $wpdb->prefix . 'woocommerce_tax_rates';

		// Check if tax rate exists
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE tax_rate_id = %d",
				$tax_rate_id
			)
		);

		if (!$existing) {
			return new WP_Error('tax_rate_not_found', 'Tax rate not found', ['status' => 404]);
		}

		$data = [];

		if (isset($params['country'])) {
			$data['tax_rate_country'] = strtoupper(sanitize_text_field($params['country']));
		}

		if (isset($params['state'])) {
			$data['tax_rate_state'] = strtoupper(sanitize_text_field($params['state']));
		}

		if (isset($params['rate'])) {
			$data['tax_rate'] = sanitize_text_field($params['rate']);
		}

		if (isset($params['name'])) {
			$data['tax_rate_name'] = sanitize_text_field($params['name']);
		}

		if (isset($params['priority'])) {
			$data['tax_rate_priority'] = (int) $params['priority'];
		}

		if (isset($params['compound'])) {
			$data['tax_rate_compound'] = (int) $params['compound'];
		}

		if (isset($params['shipping'])) {
			$data['tax_rate_shipping'] = (int) $params['shipping'];
		}

		if (isset($params['order'])) {
			$data['tax_rate_order'] = (int) $params['order'];
		}

		if (isset($params['class'])) {
			$data['tax_rate_class'] = sanitize_text_field($params['class']);
		}

		if (empty($data)) {
			return new WP_Error('no_data', 'No data provided to update', ['status' => 400]);
		}

		$updated = $wpdb->update(
			$table_name,
			$data,
			['tax_rate_id' => $tax_rate_id]
		);

		if ($updated === false) {
			return new WP_Error('update_failed', 'Failed to update tax rate', ['status' => 500]);
		}

		// Clear tax rate cache
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::invalidate_cache_group('taxes');
		}

		// Get the updated tax rate
		$tax_rate = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE tax_rate_id = %d",
				$tax_rate_id
			),
			ARRAY_A
		);

		return rest_ensure_response([
			'success' => true,
			'message' => 'Tax rate updated successfully',
			'tax_rate' => $this->format_tax_rate($tax_rate),
		]);
	}

	/**
	 * Delete a tax rate
	 */
	public function delete_tax_rate(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		global $wpdb;
		$tax_rate_id = (int) $request['id'];
		$table_name = $wpdb->prefix . 'woocommerce_tax_rates';

		// Check if tax rate exists
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE tax_rate_id = %d",
				$tax_rate_id
			)
		);

		if (!$existing) {
			return new WP_Error('tax_rate_not_found', 'Tax rate not found', ['status' => 404]);
		}

		$deleted = $wpdb->delete(
			$table_name,
			['tax_rate_id' => $tax_rate_id]
		);

		if ($deleted === false) {
			return new WP_Error('delete_failed', 'Failed to delete tax rate', ['status' => 500]);
		}

		// Clear tax rate cache
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::invalidate_cache_group('taxes');
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Tax rate deleted successfully',
		]);
	}

	/**
	 * Get all tax classes
	 */
	public function get_tax_classes(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$tax_classes = WC_Tax::get_tax_classes();
		$classes = [];

		// Add standard tax class
		$classes[] = [
			'slug' => 'standard',
			'name' => 'Standard',
		];

		// Add additional tax classes
		foreach ($tax_classes as $class) {
			$slug = sanitize_title($class);
			$classes[] = [
				'slug' => $slug,
				'name' => $class,
			];
		}

		return rest_ensure_response([
			'tax_classes' => $classes,
			'total' => count($classes),
		]);
	}

	/**
	 * Create a new tax class
	 */
	public function create_tax_class(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		if (empty($params['name'])) {
			return new WP_Error('missing_name', 'Tax class name is required', ['status' => 400]);
		}

		$class_name = sanitize_text_field($params['name']);

		// Get existing tax classes
		$existing_classes = WC_Tax::get_tax_classes();

		// Check if class already exists
		if (in_array($class_name, $existing_classes)) {
			return new WP_Error('class_exists', 'Tax class already exists', ['status' => 400]);
		}

		// Add the new tax class
		$existing_classes[] = $class_name;
		$updated = update_option('woocommerce_tax_classes', implode("\n", $existing_classes));

		if (!$updated) {
			return new WP_Error('create_failed', 'Failed to create tax class', ['status' => 500]);
		}

		$slug = sanitize_title($class_name);

		return rest_ensure_response([
			'success' => true,
			'message' => 'Tax class created successfully',
			'tax_class' => [
				'slug' => $slug,
				'name' => $class_name,
			],
		]);
	}

	/**
	 * Delete a tax class
	 */
	public function delete_tax_class(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$slug = sanitize_text_field($request['slug']);

		// Cannot delete standard tax class
		if ($slug === 'standard') {
			return new WP_Error('cannot_delete_standard', 'Cannot delete standard tax class', ['status' => 400]);
		}

		// Get existing tax classes
		$existing_classes = WC_Tax::get_tax_classes();
		$classes_array = [];

		// Find and remove the class
		$found = false;
		foreach ($existing_classes as $class) {
			$class_slug = sanitize_title($class);
			if ($class_slug !== $slug) {
				$classes_array[] = $class;
			} else {
				$found = true;
			}
		}

		if (!$found) {
			return new WP_Error('class_not_found', 'Tax class not found', ['status' => 404]);
		}

		// Update the option
		$updated = update_option('woocommerce_tax_classes', implode("\n", $classes_array));

		if (!$updated) {
			return new WP_Error('delete_failed', 'Failed to delete tax class', ['status' => 500]);
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Tax class deleted successfully',
		]);
	}

	/**
	 * Format tax rate for response
	 */
	private function format_tax_rate($rate) {
		return [
			'id' => (int) $rate['tax_rate_id'],
			'country' => $rate['tax_rate_country'],
			'state' => $rate['tax_rate_state'],
			'postcode' => isset($rate['tax_rate_postcode']) ? $rate['tax_rate_postcode'] : '',
			'city' => isset($rate['tax_rate_city']) ? $rate['tax_rate_city'] : '',
			'rate' => $rate['tax_rate'],
			'name' => $rate['tax_rate_name'],
			'priority' => (int) $rate['tax_rate_priority'],
			'compound' => (bool) $rate['tax_rate_compound'],
			'shipping' => (bool) $rate['tax_rate_shipping'],
			'order' => (int) $rate['tax_rate_order'],
			'class' => $rate['tax_rate_class'] ? $rate['tax_rate_class'] : 'standard',
		];
	}
}

