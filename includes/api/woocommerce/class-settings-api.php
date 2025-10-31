<?php

class RESTBridge_WooCommerce_Settings_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings', [
			'methods' => 'GET',
			'callback' => [$this, 'get_all_settings'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_settings_group'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_settings_group'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<key>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_setting'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<key>[a-zA-Z0-9_-]+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_setting'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_all_settings() {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$settings = [
			'general' => $this->get_general_settings(),
			'products' => $this->get_products_settings(),
			'shipping' => $this->get_shipping_settings(),
			'tax' => $this->get_tax_settings(),
			'checkout' => $this->get_checkout_settings(),
			'account' => $this->get_account_settings(),
			'email' => $this->get_email_settings(),
			'advanced' => $this->get_advanced_settings(),
		];

		return rest_ensure_response([
			'settings' => $settings,
			'timestamp' => current_time('mysql'),
		]);
	}

	public function get_settings_group(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$groups = [
			'general' => [$this, 'get_general_settings'],
			'products' => [$this, 'get_products_settings'],
			'shipping' => [$this, 'get_shipping_settings'],
			'tax' => [$this, 'get_tax_settings'],
			'checkout' => [$this, 'get_checkout_settings'],
			'account' => [$this, 'get_account_settings'],
			'email' => [$this, 'get_email_settings'],
			'advanced' => [$this, 'get_advanced_settings'],
		];

		if (!isset($groups[$group])) {
			return new WP_Error('invalid_group', 'Invalid settings group', ['status' => 400]);
		}

		return rest_ensure_response([
			'group' => $group,
			'settings' => call_user_func($groups[$group]),
		]);
	}

	public function update_settings_group(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$params = $request->get_json_params();

		$groups = [
			'general' => [$this, 'update_general_settings'],
			'products' => [$this, 'update_products_settings'],
			'shipping' => [$this, 'update_shipping_settings'],
			'tax' => [$this, 'update_tax_settings'],
			'checkout' => [$this, 'update_checkout_settings'],
			'account' => [$this, 'update_account_settings'],
			'email' => [$this, 'update_email_settings'],
			'advanced' => [$this, 'update_advanced_settings'],
		];

		if (!isset($groups[$group])) {
			return new WP_Error('invalid_group', 'Invalid settings group', ['status' => 400]);
		}

		$result = call_user_func($groups[$group], $params);

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response([
			'success' => true,
			'group' => $group,
			'message' => 'Settings updated successfully',
			'settings' => call_user_func([$this, 'get_' . $group . '_settings']),
		]);
	}

	public function get_setting(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$key = sanitize_text_field($request['key']);

		$value = get_option('woocommerce_' . $key, null);

		if ($value === null) {
			return new WP_Error('setting_not_found', 'Setting not found', ['status' => 404]);
		}

		return rest_ensure_response([
			'group' => $group,
			'key' => $key,
			'value' => $value,
		]);
	}

	public function update_setting(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$key = sanitize_text_field($request['key']);
		$params = $request->get_json_params();

		if (!isset($params['value'])) {
			return new WP_Error('missing_value', 'Value is required', ['status' => 400]);
		}

		$value = $params['value'];
		$option_key = 'woocommerce_' . $key;

		$updated = update_option($option_key, $value);

		return rest_ensure_response([
			'success' => $updated,
			'group' => $group,
			'key' => $key,
			'value' => get_option($option_key),
		]);
	}

	// General Settings
	private function get_general_settings() {
		return [
			'store_address' => get_option('woocommerce_store_address'),
			'store_address_2' => get_option('woocommerce_store_address_2'),
			'store_city' => get_option('woocommerce_store_city'),
			'store_postcode' => get_option('woocommerce_store_postcode'),
			'store_country' => get_option('woocommerce_default_country'),
			'currency' => get_option('woocommerce_currency'),
			'currency_pos' => get_option('woocommerce_currency_pos'),
			'thousand_sep' => get_option('woocommerce_price_thousand_sep'),
			'decimal_sep' => get_option('woocommerce_price_decimal_sep'),
			'num_decimals' => get_option('woocommerce_price_num_decimals'),
			'enable_tax' => get_option('woocommerce_calc_taxes'),
			'enable_coupons' => get_option('woocommerce_enable_coupons'),
			'enable_guest_checkout' => get_option('woocommerce_enable_guest_checkout'),
		];
	}

	private function update_general_settings($params) {
		$allowed_keys = [
			'store_address', 'store_address_2', 'store_city', 'store_postcode',
			'store_country', 'currency', 'currency_pos', 'thousand_sep',
			'decimal_sep', 'num_decimals', 'enable_tax', 'enable_coupons',
			'enable_guest_checkout',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Products Settings
	private function get_products_settings() {
		return [
			'shop_page' => get_option('woocommerce_shop_page_id'),
			'cart_page' => get_option('woocommerce_cart_page_id'),
			'checkout_page' => get_option('woocommerce_checkout_page_id'),
			'myaccount_page' => get_option('woocommerce_myaccount_page_id'),
			'terms_page' => get_option('woocommerce_terms_page_id'),
			'weight_unit' => get_option('woocommerce_weight_unit'),
			'dimensions_unit' => get_option('woocommerce_dimension_unit'),
			'enable_reviews' => get_option('woocommerce_enable_reviews'),
			'review_rating_required' => get_option('woocommerce_review_rating_required'),
			'review_rating_required_text' => get_option('woocommerce_review_rating_required'),
			'enable_product_reviews' => get_option('woocommerce_enable_product_reviews'),
			'manage_stock' => get_option('woocommerce_manage_stock'),
			'hold_stock_minutes' => get_option('woocommerce_hold_stock_minutes'),
			'notify_low_stock' => get_option('woocommerce_notify_low_stock'),
			'notify_no_stock' => get_option('woocommerce_notify_no_stock'),
			'low_stock_amount' => get_option('woocommerce_notify_low_stock_amount'),
			'hide_out_of_stock_items' => get_option('woocommerce_hide_out_of_stock_items'),
			'stock_display_format' => get_option('woocommerce_stock_format'),
		];
	}

	private function update_products_settings($params) {
		$allowed_keys = [
			'shop_page', 'cart_page', 'checkout_page', 'myaccount_page', 'terms_page',
			'weight_unit', 'dimensions_unit', 'enable_reviews', 'review_rating_required',
			'enable_product_reviews', 'manage_stock', 'hold_stock_minutes',
			'notify_low_stock', 'notify_no_stock', 'low_stock_amount',
			'hide_out_of_stock_items', 'stock_display_format',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Shipping Settings
	private function get_shipping_settings() {
		return [
			'shipping_zones_enabled' => get_option('woocommerce_ship_to_countries'),
			'shipping_calculator' => get_option('woocommerce_enable_shipping_calc'),
			'shipping_debug_mode' => get_option('woocommerce_shipping_debug_mode'),
			'default_shipping_method' => get_option('woocommerce_default_shipping_method'),
		];
	}

	private function update_shipping_settings($params) {
		$allowed_keys = [
			'ship_to_countries', 'enable_shipping_calc', 'shipping_debug_mode',
			'default_shipping_method',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Tax Settings
	private function get_tax_settings() {
		return [
			'enable_taxes' => get_option('woocommerce_calc_taxes'),
			'prices_include_tax' => get_option('woocommerce_prices_include_tax'),
			'tax_based_on' => get_option('woocommerce_tax_based_on'),
			'shipping_tax_class' => get_option('woocommerce_shipping_tax_class'),
			'tax_round_at_subtotal' => get_option('woocommerce_tax_round_at_subtotal'),
			'additional_tax_classes' => get_option('woocommerce_tax_classes'),
			'display_tax_totals' => get_option('woocommerce_tax_total_display'),
			'tax_display_shop' => get_option('woocommerce_tax_display_shop'),
			'tax_display_cart' => get_option('woocommerce_tax_display_cart'),
		];
	}

	private function update_tax_settings($params) {
		$allowed_keys = [
			'calc_taxes', 'prices_include_tax', 'tax_based_on', 'shipping_tax_class',
			'tax_round_at_subtotal', 'tax_classes', 'tax_total_display',
			'tax_display_shop', 'tax_display_cart',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Checkout Settings
	private function get_checkout_settings() {
		return [
			'checkout_page' => get_option('woocommerce_checkout_page_id'),
			'enable_guest_checkout' => get_option('woocommerce_enable_guest_checkout'),
			'force_ssl_checkout' => get_option('woocommerce_force_ssl_checkout'),
			'unforce_ssl_checkout' => get_option('woocommerce_unforce_ssl_checkout'),
			'checkout_privacy_policy_text' => get_option('woocommerce_checkout_privacy_policy_text'),
			'checkout_terms_and_conditions_checkbox_text' => get_option('woocommerce_checkout_terms_and_conditions_checkbox_text'),
			'registration_generate_username' => get_option('woocommerce_registration_generate_username'),
			'registration_generate_password' => get_option('woocommerce_registration_generate_password'),
		];
	}

	private function update_checkout_settings($params) {
		$allowed_keys = [
			'checkout_page_id', 'enable_guest_checkout', 'force_ssl_checkout',
			'unforce_ssl_checkout', 'checkout_privacy_policy_text',
			'checkout_terms_and_conditions_checkbox_text',
			'registration_generate_username', 'registration_generate_password',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Account Settings
	private function get_account_settings() {
		return [
			'myaccount_page' => get_option('woocommerce_myaccount_page_id'),
			'customer_registration' => get_option('woocommerce_enable_myaccount_registration'),
			'account_endpoint_dashboard' => get_option('woocommerce_myaccount_dashboard_endpoint'),
			'account_endpoint_orders' => get_option('woocommerce_myaccount_orders_endpoint'),
			'account_endpoint_downloads' => get_option('woocommerce_myaccount_downloads_endpoint'),
			'account_endpoint_edit_address' => get_option('woocommerce_myaccount_edit_address_endpoint'),
			'account_endpoint_edit_account' => get_option('woocommerce_myaccount_edit_account_endpoint'),
			'account_endpoint_customer_logout' => get_option('woocommerce_logout_endpoint'),
			'registration_generate_username' => get_option('woocommerce_registration_generate_username'),
			'registration_generate_password' => get_option('woocommerce_registration_generate_password'),
			'account_creation' => get_option('woocommerce_enable_signup_and_login_from_checkout'),
		];
	}

	private function update_account_settings($params) {
		$allowed_keys = [
			'myaccount_page_id', 'enable_myaccount_registration',
			'myaccount_dashboard_endpoint', 'myaccount_orders_endpoint',
			'myaccount_downloads_endpoint', 'myaccount_edit_address_endpoint',
			'myaccount_edit_account_endpoint', 'logout_endpoint',
			'registration_generate_username', 'registration_generate_password',
			'enable_signup_and_login_from_checkout',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Email Settings
	private function get_email_settings() {
		return [
			'email_from_name' => get_option('woocommerce_email_from_name'),
			'email_from_address' => get_option('woocommerce_email_from_address'),
			'email_footer_text' => get_option('woocommerce_email_footer_text'),
			'email_header_image' => get_option('woocommerce_email_header_image'),
		];
	}

	private function update_email_settings($params) {
		$allowed_keys = [
			'email_from_name', 'email_from_address', 'email_footer_text',
			'email_header_image',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	// Advanced Settings
	private function get_advanced_settings() {
		return [
			'cart_page' => get_option('woocommerce_cart_page_id'),
			'checkout_page' => get_option('woocommerce_checkout_page_id'),
			'myaccount_page' => get_option('woocommerce_myaccount_page_id'),
			'terms_page' => get_option('woocommerce_terms_page_id'),
			'api_enabled' => get_option('woocommerce_api_enabled'),
			'webhook_api_keys' => $this->get_api_keys(),
		];
	}

	private function update_advanced_settings($params) {
		$allowed_keys = [
			'cart_page_id', 'checkout_page_id', 'myaccount_page_id',
			'terms_page_id', 'api_enabled',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}

		return true;
	}

	private function get_api_keys() {
		global $wpdb;
		$keys = $wpdb->get_results(
			"SELECT key_id, user_id, description, permissions, last_access
			FROM {$wpdb->prefix}woocommerce_api_keys
			ORDER BY key_id DESC
			LIMIT 10"
		);

		$formatted_keys = [];
		foreach ($keys as $key) {
			$formatted_keys[] = [
				'key_id' => $key->key_id,
				'user_id' => $key->user_id,
				'description' => $key->description,
				'permissions' => $key->permissions,
				'last_access' => $key->last_access,
			];
		}

		return $formatted_keys;
	}

	public function check_permission() {
		return current_user_can('manage_woocommerce');
	}
}

