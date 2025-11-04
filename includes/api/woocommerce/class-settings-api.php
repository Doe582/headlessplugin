<?php

class RESTBridge_WooCommerce_Settings_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings', [
			'methods' => 'GET',
			'callback' => [$this, 'get_all_settings'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		// Nested routes: /settings/{group}/{subgroup}
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<subgroup>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_settings_subgroup'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<subgroup>[a-zA-Z0-9_-]+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_settings_subgroup'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		// Flat routes: /settings/{group} (for backwards compatibility)
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

		// Single setting: /settings/{group}/{subgroup}/{key}
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<subgroup>[a-zA-Z0-9_-]+)/(?P<key>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_setting'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/settings/(?P<group>[a-zA-Z0-9_-]+)/(?P<subgroup>[a-zA-Z0-9_-]+)/(?P<key>[a-zA-Z0-9_-]+)', [
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
			'pages' => $this->get_pages_settings(),
			'rest_api' => $this->get_rest_api_settings(),
			'webhooks' => $this->get_webhooks_settings(),
			'legacy_api' => $this->get_legacy_api_settings(),
			'woocommerce_com' => $this->get_woocommerce_com_settings(),
			'blueprint' => $this->get_blueprint_settings(),
			'features' => $this->get_features_settings(),
		];

		return rest_ensure_response([
			'settings' => $settings,
			'timestamp' => current_time('mysql'),
		]);
	}

	public function get_settings_subgroup(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$subgroup = sanitize_text_field($request['subgroup']);

		$nested_groups = [
			'products' => [
				'general' => [$this, 'get_products_general_settings'],
				'inventory' => [$this, 'get_products_inventory_settings'],
				'downloadable' => [$this, 'get_products_downloadable_settings'],
				'download_directories' => [$this, 'get_products_download_directories_settings'],
				'advanced' => [$this, 'get_products_advanced_settings'],
			],
			'shipping' => [
				'general' => [$this, 'get_shipping_general_settings'],
				'zones' => [$this, 'get_shipping_zones_settings'],
				'classes' => [$this, 'get_shipping_classes_settings'],
			],
			'tax' => [
				'general' => [$this, 'get_tax_general_settings'],
				'rates' => [$this, 'get_tax_rates_settings'],
				'classes' => [$this, 'get_tax_classes_settings'],
			],
			'checkout' => [
				'general' => [$this, 'get_checkout_general_settings'],
				'endpoints' => [$this, 'get_checkout_endpoints_settings'],
			],
			'account' => [
				'general' => [$this, 'get_account_general_settings'],
				'endpoints' => [$this, 'get_account_endpoints_settings'],
			],
			'email' => [
				'general' => [$this, 'get_email_general_settings'],
				'options' => [$this, 'get_email_options_settings'],
			],
			'advanced' => [
				'pages' => [$this, 'get_pages_settings'],
				'rest_api' => [$this, 'get_rest_api_settings'],
				'webhooks' => [$this, 'get_webhooks_settings'],
				'legacy_api' => [$this, 'get_legacy_api_settings'],
				'woocommerce_com' => [$this, 'get_woocommerce_com_settings'],
				'blueprint' => [$this, 'get_blueprint_settings'],
				'features' => [$this, 'get_features_settings'],
			],
		];

		if (!isset($nested_groups[$group][$subgroup])) {
			return new WP_Error('invalid_subgroup', 'Invalid settings subgroup', ['status' => 400]);
		}

		return rest_ensure_response([
			'group' => $group,
			'subgroup' => $subgroup,
			'settings' => call_user_func($nested_groups[$group][$subgroup]),
		]);
	}

	public function update_settings_subgroup(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$group = sanitize_text_field($request['group']);
		$subgroup = sanitize_text_field($request['subgroup']);
		$params = $request->get_json_params();

		$nested_groups = [
			'products' => [
				'general' => [$this, 'update_products_general_settings'],
				'inventory' => [$this, 'update_products_inventory_settings'],
				'downloadable' => [$this, 'update_products_downloadable_settings'],
				'download_directories' => [$this, 'update_products_download_directories_settings'],
				'advanced' => [$this, 'update_products_advanced_settings'],
			],
			'shipping' => [
				'general' => [$this, 'update_shipping_general_settings'],
				'zones' => [$this, 'update_shipping_zones_settings'],
				'classes' => [$this, 'update_shipping_classes_settings'],
			],
			'tax' => [
				'general' => [$this, 'update_tax_general_settings'],
				'rates' => [$this, 'update_tax_rates_settings'],
				'classes' => [$this, 'update_tax_classes_settings'],
			],
			'checkout' => [
				'general' => [$this, 'update_checkout_general_settings'],
				'endpoints' => [$this, 'update_checkout_endpoints_settings'],
			],
			'account' => [
				'general' => [$this, 'update_account_general_settings'],
				'endpoints' => [$this, 'update_account_endpoints_settings'],
			],
			'email' => [
				'general' => [$this, 'update_email_general_settings'],
				'options' => [$this, 'update_email_options_settings'],
			],
			'advanced' => [
				'pages' => [$this, 'update_pages_settings'],
				'rest_api' => [$this, 'update_rest_api_settings'],
				'webhooks' => [$this, 'update_webhooks_settings'],
				'legacy_api' => [$this, 'update_legacy_api_settings'],
				'woocommerce_com' => [$this, 'update_woocommerce_com_settings'],
				'blueprint' => [$this, 'update_blueprint_settings'],
				'features' => [$this, 'update_features_settings'],
			],
		];

		if (!isset($nested_groups[$group][$subgroup])) {
			return new WP_Error('invalid_subgroup', 'Invalid settings subgroup', ['status' => 400]);
		}

		$result = call_user_func($nested_groups[$group][$subgroup], $params);

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response([
			'success' => true,
			'group' => $group,
			'subgroup' => $subgroup,
			'message' => 'Settings updated successfully',
			'settings' => call_user_func($nested_groups[$group][$subgroup]),
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
			'pages' => [$this, 'get_pages_settings'],
			'rest_api' => [$this, 'get_rest_api_settings'],
			'webhooks' => [$this, 'get_webhooks_settings'],
			'legacy_api' => [$this, 'get_legacy_api_settings'],
			'woocommerce_com' => [$this, 'get_woocommerce_com_settings'],
			'blueprint' => [$this, 'get_blueprint_settings'],
			'features' => [$this, 'get_features_settings'],
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
			'pages' => [$this, 'update_pages_settings'],
			'rest_api' => [$this, 'update_rest_api_settings'],
			'webhooks' => [$this, 'update_webhooks_settings'],
			'legacy_api' => [$this, 'update_legacy_api_settings'],
			'woocommerce_com' => [$this, 'update_woocommerce_com_settings'],
			'blueprint' => [$this, 'update_blueprint_settings'],
			'features' => [$this, 'update_features_settings'],
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
		$subgroup = isset($request['subgroup']) ? sanitize_text_field($request['subgroup']) : null;
		$key = sanitize_text_field($request['key']);

		// Handle nested settings: /settings/{group}/{subgroup}/{key}
		if ($subgroup) {
			$option_key = 'woocommerce_' . $key;
			$value = get_option($option_key, null);

			if ($value === null) {
				return new WP_Error('setting_not_found', 'Setting not found', ['status' => 404]);
			}

			return rest_ensure_response([
				'group' => $group,
				'subgroup' => $subgroup,
				'key' => $key,
				'value' => $value,
			]);
		}

		// Handle flat settings: /settings/{group}/{key} (legacy)
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
		$subgroup = isset($request['subgroup']) ? sanitize_text_field($request['subgroup']) : null;
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
			'subgroup' => $subgroup,
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

	// Products Settings - Combined (for backwards compatibility)
	private function get_products_settings() {
		return [
			'general' => $this->get_products_general_settings(),
			'inventory' => $this->get_products_inventory_settings(),
			'downloadable' => $this->get_products_downloadable_settings(),
			'download_directories' => $this->get_products_download_directories_settings(),
			'advanced' => $this->get_products_advanced_settings(),
		];
	}

	private function update_products_settings($params) {
		if (isset($params['general'])) {
			$this->update_products_general_settings($params['general']);
		}
		if (isset($params['inventory'])) {
			$this->update_products_inventory_settings($params['inventory']);
		}
		if (isset($params['downloadable'])) {
			$this->update_products_downloadable_settings($params['downloadable']);
		}
		if (isset($params['download_directories'])) {
			$this->update_products_download_directories_settings($params['download_directories']);
		}
		if (isset($params['advanced'])) {
			$this->update_products_advanced_settings($params['advanced']);
		}
		return true;
	}

	// Products - General Settings
	private function get_products_general_settings() {
		return [
			'shop_page' => get_option('woocommerce_shop_page_id'),
			'weight_unit' => get_option('woocommerce_weight_unit'),
			'dimensions_unit' => get_option('woocommerce_dimension_unit'),
			'enable_reviews' => get_option('woocommerce_enable_reviews') === 'yes',
			'review_rating_required' => get_option('woocommerce_review_rating_required') === 'yes',
			'enable_product_reviews' => get_option('woocommerce_enable_product_reviews') === 'yes',
		];
	}

	private function update_products_general_settings($params) {
		$allowed_keys = [
			'shop_page_id', 'weight_unit', 'dimension_unit',
			'enable_reviews', 'review_rating_required', 'enable_product_reviews',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['shop_page', 'weight_unit', 'dimensions_unit', 'enable_reviews', 'review_rating_required', 'enable_product_reviews'])) {
				$option_key = strpos($key, 'woocommerce_') === 0 ? $key : 'woocommerce_' . str_replace(['shop_page'], ['shop_page_id'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Products - Inventory Settings
	private function get_products_inventory_settings() {
		return [
			'manage_stock' => get_option('woocommerce_manage_stock') === 'yes',
			'hold_stock_minutes' => get_option('woocommerce_hold_stock_minutes'),
			'notify_low_stock' => get_option('woocommerce_notify_low_stock') === 'yes',
			'notify_no_stock' => get_option('woocommerce_notify_no_stock') === 'yes',
			'low_stock_amount' => get_option('woocommerce_notify_low_stock_amount'),
			'hide_out_of_stock_items' => get_option('woocommerce_hide_out_of_stock_items') === 'yes',
			'stock_display_format' => get_option('woocommerce_stock_format'),
		];
	}

	private function update_products_inventory_settings($params) {
		$allowed_keys = [
			'manage_stock', 'hold_stock_minutes', 'notify_low_stock',
			'notify_no_stock', 'notify_low_stock_amount', 'hide_out_of_stock_items',
			'stock_format',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['low_stock_amount', 'stock_display_format'])) {
				$option_key = 'woocommerce_' . str_replace(['stock_display_format'], ['stock_format'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Products - Downloadable Settings
	private function get_products_downloadable_settings() {
		return [
			'enable_downloads' => get_option('woocommerce_enable_customer_downloads') === 'yes',
			'download_method' => get_option('woocommerce_file_download_method', 'force'),
			'download_require_login' => get_option('woocommerce_downloads_require_login') === 'yes',
			'download_grant_access_after_payment' => get_option('woocommerce_downloads_grant_access_after_payment') === 'yes',
		];
	}

	private function update_products_downloadable_settings($params) {
		$allowed_keys = [
			'enable_customer_downloads', 'file_download_method',
			'downloads_require_login', 'downloads_grant_access_after_payment',
		];

		foreach ($params as $key => $value) {
			$mapping = [
				'enable_downloads' => 'enable_customer_downloads',
				'download_method' => 'file_download_method',
				'download_require_login' => 'downloads_require_login',
				'download_grant_access_after_payment' => 'downloads_grant_access_after_payment',
			];
			
			$option_key = isset($mapping[$key]) ? $mapping[$key] : $key;
			if (in_array($option_key, $allowed_keys)) {
				if (is_bool($value)) {
					update_option('woocommerce_' . $option_key, $value ? 'yes' : 'no');
				} else {
					update_option('woocommerce_' . $option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Products - Download Directories Settings
	private function get_products_download_directories_settings() {
		return [
			'approved_directories' => get_option('woocommerce_downloads_approved_directories', []),
		];
	}

	private function update_products_download_directories_settings($params) {
		if (isset($params['approved_directories']) && is_array($params['approved_directories'])) {
			update_option('woocommerce_downloads_approved_directories', array_map('sanitize_text_field', $params['approved_directories']));
		}
		return true;
	}

	// Products - Advanced Settings
	private function get_products_advanced_settings() {
		return [
			'product_reviews_enabled' => get_option('woocommerce_enable_product_reviews') === 'yes',
			'review_moderation' => get_option('woocommerce_review_moderation') === 'yes',
		];
	}

	private function update_products_advanced_settings($params) {
		$allowed_keys = ['enable_product_reviews', 'review_moderation'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
			}
		}
		return true;
	}

	// Shipping Settings - Combined
	private function get_shipping_settings() {
		return [
			'general' => $this->get_shipping_general_settings(),
			'zones' => $this->get_shipping_zones_settings(),
			'classes' => $this->get_shipping_classes_settings(),
		];
	}

	private function update_shipping_settings($params) {
		if (isset($params['general'])) {
			$this->update_shipping_general_settings($params['general']);
		}
		if (isset($params['zones'])) {
			$this->update_shipping_zones_settings($params['zones']);
		}
		if (isset($params['classes'])) {
			$this->update_shipping_classes_settings($params['classes']);
		}
		return true;
	}

	// Shipping - General Settings
	private function get_shipping_general_settings() {
		return [
			'shipping_zones_enabled' => get_option('woocommerce_ship_to_countries'),
			'shipping_calculator' => get_option('woocommerce_enable_shipping_calc') === 'yes',
			'shipping_debug_mode' => get_option('woocommerce_shipping_debug_mode') === 'yes',
			'default_shipping_method' => get_option('woocommerce_default_shipping_method'),
		];
	}

	private function update_shipping_general_settings($params) {
		$allowed_keys = [
			'ship_to_countries', 'enable_shipping_calc', 'shipping_debug_mode',
			'default_shipping_method',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['shipping_zones_enabled', 'shipping_calculator', 'shipping_debug_mode', 'default_shipping_method'])) {
				$option_key = 'woocommerce_' . str_replace(['shipping_zones_enabled', 'shipping_calculator', 'shipping_debug_mode'], ['ship_to_countries', 'enable_shipping_calc', 'shipping_debug_mode'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Shipping - Zones Settings
	private function get_shipping_zones_settings() {
		if (!function_exists('WC')) {
			return [];
		}
		
		// Get shipping zones
		$zones = WC_Shipping_Zones::get_zones();
		$formatted_zones = [];
		
		foreach ($zones as $zone_id => $zone_data) {
			$zone = WC_Shipping_Zones::get_zone($zone_id);
			$formatted_zones[] = [
				'zone_id' => (int) $zone_id,
				'zone_name' => $zone->get_zone_name(),
				'zone_order' => $zone->get_zone_order(),
				'zone_locations' => $zone->get_zone_locations(),
			];
		}
		
		// Get rest of the world zone
		$world_zone = WC_Shipping_Zones::get_zone(0);
		if ($world_zone) {
			$formatted_zones[] = [
				'zone_id' => 0,
				'zone_name' => $world_zone->get_zone_name(),
				'zone_order' => $world_zone->get_zone_order(),
				'zone_locations' => $world_zone->get_zone_locations(),
			];
		}
		
		return [
			'zones' => $formatted_zones,
			'total' => count($formatted_zones),
		];
	}

	private function update_shipping_zones_settings($params) {
		// Shipping zones are managed through the shipping zones API
		// This is mainly for reading settings
		return true;
	}

	// Shipping - Classes Settings
	private function get_shipping_classes_settings() {
		if (!function_exists('WC')) {
			return [];
		}
		
		$shipping_classes = WC()->shipping->get_shipping_classes();
		$formatted_classes = [];
		
		foreach ($shipping_classes as $class) {
			$formatted_classes[] = [
				'term_id' => (int) $class->term_id,
				'name' => $class->name,
				'slug' => $class->slug,
				'description' => $class->description,
				'count' => (int) $class->count,
			];
		}
		
		return [
			'classes' => $formatted_classes,
			'total' => count($formatted_classes),
		];
	}

	private function update_shipping_classes_settings($params) {
		// Shipping classes are managed through the shipping classes API
		// This is mainly for reading settings
		return true;
	}

	// Tax Settings - Combined
	private function get_tax_settings() {
		return [
			'general' => $this->get_tax_general_settings(),
			'rates' => $this->get_tax_rates_settings(),
			'classes' => $this->get_tax_classes_settings(),
		];
	}

	private function update_tax_settings($params) {
		if (isset($params['general'])) {
			$this->update_tax_general_settings($params['general']);
		}
		if (isset($params['rates'])) {
			$this->update_tax_rates_settings($params['rates']);
		}
		if (isset($params['classes'])) {
			$this->update_tax_classes_settings($params['classes']);
		}
		return true;
	}

	// Tax - General Settings
	private function get_tax_general_settings() {
		return [
			'enable_taxes' => get_option('woocommerce_calc_taxes') === 'yes',
			'prices_include_tax' => get_option('woocommerce_prices_include_tax') === 'yes',
			'tax_based_on' => get_option('woocommerce_tax_based_on'),
			'shipping_tax_class' => get_option('woocommerce_shipping_tax_class'),
			'tax_round_at_subtotal' => get_option('woocommerce_tax_round_at_subtotal') === 'yes',
			'display_tax_totals' => get_option('woocommerce_tax_total_display'),
			'tax_display_shop' => get_option('woocommerce_tax_display_shop'),
			'tax_display_cart' => get_option('woocommerce_tax_display_cart'),
		];
	}

	private function update_tax_general_settings($params) {
		$allowed_keys = [
			'calc_taxes', 'prices_include_tax', 'tax_based_on', 'shipping_tax_class',
			'tax_round_at_subtotal', 'tax_total_display',
			'tax_display_shop', 'tax_display_cart',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['enable_taxes', 'prices_include_tax', 'tax_based_on', 'shipping_tax_class', 'tax_round_at_subtotal', 'display_tax_totals', 'tax_display_shop', 'tax_display_cart'])) {
				$option_key = 'woocommerce_' . str_replace(['enable_taxes', 'display_tax_totals'], ['calc_taxes', 'tax_total_display'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Tax - Rates Settings
	private function get_tax_rates_settings() {
		if (!function_exists('WC')) {
			return [];
		}
		
		// Get tax rates
		global $wpdb;
		$rates = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order, tax_rate_id"
		);
		
		$formatted_rates = [];
		foreach ($rates as $rate) {
			$formatted_rates[] = [
				'tax_rate_id' => (int) $rate->tax_rate_id,
				'tax_rate_country' => $rate->tax_rate_country,
				'tax_rate_state' => $rate->tax_rate_state,
				'tax_rate' => $rate->tax_rate,
				'tax_rate_name' => $rate->tax_rate_name,
				'tax_rate_priority' => (int) $rate->tax_rate_priority,
				'tax_rate_compound' => (int) $rate->tax_rate_compound,
				'tax_rate_shipping' => (int) $rate->tax_rate_shipping,
				'tax_rate_order' => (int) $rate->tax_rate_order,
				'tax_rate_class' => $rate->tax_rate_class,
			];
		}
		
		return [
			'rates' => $formatted_rates,
			'total' => count($formatted_rates),
		];
	}

	private function update_tax_rates_settings($params) {
		// Tax rates are managed through the tax rates API
		// This is mainly for reading settings
		return true;
	}

	// Tax - Classes Settings
	private function get_tax_classes_settings() {
		$tax_classes = WC_Tax::get_tax_classes();
		$formatted_classes = [];
		
		foreach ($tax_classes as $class) {
			$formatted_classes[] = [
				'name' => $class,
				'slug' => sanitize_title($class),
			];
		}
		
		// Also get standard tax class
		$formatted_classes[] = [
			'name' => 'Standard',
			'slug' => '',
		];
		
		return [
			'classes' => $formatted_classes,
			'additional_classes' => get_option('woocommerce_tax_classes'),
			'total' => count($formatted_classes),
		];
	}

	private function update_tax_classes_settings($params) {
		if (isset($params['additional_classes'])) {
			update_option('woocommerce_tax_classes', sanitize_textarea_field($params['additional_classes']));
		}
		return true;
	}

	// Checkout Settings - Combined
	private function get_checkout_settings() {
		return [
			'general' => $this->get_checkout_general_settings(),
			'endpoints' => $this->get_checkout_endpoints_settings(),
		];
	}

	private function update_checkout_settings($params) {
		if (isset($params['general'])) {
			$this->update_checkout_general_settings($params['general']);
		}
		if (isset($params['endpoints'])) {
			$this->update_checkout_endpoints_settings($params['endpoints']);
		}
		return true;
	}

	// Checkout - General Settings
	private function get_checkout_general_settings() {
		return [
			'checkout_page' => get_option('woocommerce_checkout_page_id'),
			'enable_guest_checkout' => get_option('woocommerce_enable_guest_checkout') === 'yes',
			'force_ssl_checkout' => get_option('woocommerce_force_ssl_checkout') === 'yes',
			'checkout_privacy_policy_text' => get_option('woocommerce_checkout_privacy_policy_text'),
			'checkout_terms_and_conditions_checkbox_text' => get_option('woocommerce_checkout_terms_and_conditions_checkbox_text'),
			'registration_generate_username' => get_option('woocommerce_registration_generate_username') === 'yes',
			'registration_generate_password' => get_option('woocommerce_registration_generate_password') === 'yes',
		];
	}

	private function update_checkout_general_settings($params) {
		$allowed_keys = [
			'checkout_page_id', 'enable_guest_checkout', 'force_ssl_checkout',
			'checkout_privacy_policy_text', 'checkout_terms_and_conditions_checkbox_text',
			'registration_generate_username', 'registration_generate_password',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['checkout_page', 'enable_guest_checkout', 'force_ssl_checkout', 'checkout_privacy_policy_text', 'checkout_terms_and_conditions_checkbox_text', 'registration_generate_username', 'registration_generate_password'])) {
				$option_key = 'woocommerce_' . str_replace(['checkout_page'], ['checkout_page_id'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Checkout - Endpoints Settings
	private function get_checkout_endpoints_settings() {
		return [
			'pay' => get_option('woocommerce_checkout_pay_endpoint', 'order-pay'),
			'order_received' => get_option('woocommerce_checkout_order_received_endpoint', 'order-received'),
			'add_payment_method' => get_option('woocommerce_checkout_add_payment_method_endpoint', 'add-payment-method'),
			'delete_payment_method' => get_option('woocommerce_checkout_delete_payment_method_endpoint', 'delete-payment-method'),
			'set_default_payment_method' => get_option('woocommerce_checkout_set_default_payment_method_endpoint', 'set-default-payment-method'),
		];
	}

	private function update_checkout_endpoints_settings($params) {
		$allowed_keys = [
			'checkout_pay_endpoint', 'checkout_order_received_endpoint',
			'checkout_add_payment_method_endpoint', 'checkout_delete_payment_method_endpoint',
			'checkout_set_default_payment_method_endpoint',
		];

		foreach ($params as $key => $value) {
			$mapping = [
				'pay' => 'checkout_pay_endpoint',
				'order_received' => 'checkout_order_received_endpoint',
				'add_payment_method' => 'checkout_add_payment_method_endpoint',
				'delete_payment_method' => 'checkout_delete_payment_method_endpoint',
				'set_default_payment_method' => 'checkout_set_default_payment_method_endpoint',
			];
			
			$option_key = isset($mapping[$key]) ? $mapping[$key] : $key;
			if (in_array($option_key, $allowed_keys)) {
				update_option('woocommerce_' . $option_key, sanitize_text_field($value));
			}
		}
		return true;
	}

	// Account Settings - Combined
	private function get_account_settings() {
		return [
			'general' => $this->get_account_general_settings(),
			'endpoints' => $this->get_account_endpoints_settings(),
		];
	}

	private function update_account_settings($params) {
		if (isset($params['general'])) {
			$this->update_account_general_settings($params['general']);
		}
		if (isset($params['endpoints'])) {
			$this->update_account_endpoints_settings($params['endpoints']);
		}
		return true;
	}

	// Account - General Settings
	private function get_account_general_settings() {
		return [
			'myaccount_page' => get_option('woocommerce_myaccount_page_id'),
			'customer_registration' => get_option('woocommerce_enable_myaccount_registration') === 'yes',
			'registration_generate_username' => get_option('woocommerce_registration_generate_username') === 'yes',
			'registration_generate_password' => get_option('woocommerce_registration_generate_password') === 'yes',
			'account_creation' => get_option('woocommerce_enable_signup_and_login_from_checkout') === 'yes',
		];
	}

	private function update_account_general_settings($params) {
		$allowed_keys = [
			'myaccount_page_id', 'enable_myaccount_registration',
			'registration_generate_username', 'registration_generate_password',
			'enable_signup_and_login_from_checkout',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['myaccount_page', 'customer_registration', 'registration_generate_username', 'registration_generate_password', 'account_creation'])) {
				$option_key = 'woocommerce_' . str_replace(['myaccount_page', 'customer_registration', 'account_creation'], ['myaccount_page_id', 'enable_myaccount_registration', 'enable_signup_and_login_from_checkout'], $key);
				if (is_bool($value)) {
					update_option($option_key, $value ? 'yes' : 'no');
				} else {
					update_option($option_key, sanitize_text_field($value));
				}
			}
		}
		return true;
	}

	// Account - Endpoints Settings
	private function get_account_endpoints_settings() {
		return [
			'orders' => get_option('woocommerce_myaccount_orders_endpoint', 'orders'),
			'view_order' => get_option('woocommerce_myaccount_view_order_endpoint', 'view-order'),
			'downloads' => get_option('woocommerce_myaccount_downloads_endpoint', 'downloads'),
			'edit_account' => get_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account'),
			'edit_address' => get_option('woocommerce_myaccount_edit_address_endpoint', 'edit-address'),
			'payment_methods' => get_option('woocommerce_myaccount_payment_methods_endpoint', 'payment-methods'),
			'lost_password' => get_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password'),
			'customer_logout' => get_option('woocommerce_logout_endpoint', 'customer-logout'),
		];
	}

	private function update_account_endpoints_settings($params) {
		$allowed_keys = [
			'myaccount_orders_endpoint', 'myaccount_view_order_endpoint',
			'myaccount_downloads_endpoint', 'myaccount_edit_account_endpoint',
			'myaccount_edit_address_endpoint', 'myaccount_payment_methods_endpoint',
			'myaccount_lost_password_endpoint', 'logout_endpoint',
		];

		foreach ($params as $key => $value) {
			$mapping = [
				'orders' => 'myaccount_orders_endpoint',
				'view_order' => 'myaccount_view_order_endpoint',
				'downloads' => 'myaccount_downloads_endpoint',
				'edit_account' => 'myaccount_edit_account_endpoint',
				'edit_address' => 'myaccount_edit_address_endpoint',
				'payment_methods' => 'myaccount_payment_methods_endpoint',
				'lost_password' => 'myaccount_lost_password_endpoint',
				'customer_logout' => 'logout_endpoint',
			];
			
			$option_key = isset($mapping[$key]) ? $mapping[$key] : $key;
			if (in_array($option_key, $allowed_keys)) {
				update_option('woocommerce_' . $option_key, sanitize_text_field($value));
			}
		}
		return true;
	}

	// Email Settings - Combined
	private function get_email_settings() {
		return [
			'general' => $this->get_email_general_settings(),
			'options' => $this->get_email_options_settings(),
		];
	}

	private function update_email_settings($params) {
		if (isset($params['general'])) {
			$this->update_email_general_settings($params['general']);
		}
		if (isset($params['options'])) {
			$this->update_email_options_settings($params['options']);
		}
		return true;
	}

	// Email - General Settings
	private function get_email_general_settings() {
		return [
			'email_from_name' => get_option('woocommerce_email_from_name'),
			'email_from_address' => get_option('woocommerce_email_from_address'),
			'email_footer_text' => get_option('woocommerce_email_footer_text'),
			'email_header_image' => get_option('woocommerce_email_header_image'),
		];
	}

	private function update_email_general_settings($params) {
		$allowed_keys = ['email_from_name', 'email_from_address', 'email_footer_text', 'email_header_image'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, sanitize_text_field($value));
			}
		}
		return true;
	}

	// Email - Options Settings
	private function get_email_options_settings() {
		return [
			'base_color' => get_option('woocommerce_email_base_color'),
			'background_color' => get_option('woocommerce_email_background_color'),
			'body_background_color' => get_option('woocommerce_email_body_background_color'),
			'text_color' => get_option('woocommerce_email_text_color'),
		];
	}

	private function update_email_options_settings($params) {
		$allowed_keys = ['email_base_color', 'email_background_color', 'email_body_background_color', 'email_text_color'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys) || in_array($key, ['base_color', 'background_color', 'body_background_color', 'text_color'])) {
				$option_key = 'woocommerce_email_' . $key;
				if (strpos($key, 'email_') !== 0) {
					update_option($option_key, sanitize_text_field($value));
				} else {
					update_option('woocommerce_' . $key, sanitize_text_field($value));
				}
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

	// Pages Settings
	private function get_pages_settings() {
		// Get page IDs
		$cart_page_id = get_option('woocommerce_cart_page_id');
		$checkout_page_id = get_option('woocommerce_checkout_page_id');
		$myaccount_page_id = get_option('woocommerce_myaccount_page_id');
		$terms_page_id = get_option('woocommerce_terms_page_id');

		// Helper function to get page details
		$get_page_details = function($page_id) {
			if (!$page_id) {
				return ['id' => null, 'title' => null, 'permalink' => null];
			}
			$page = get_post($page_id);
			if (!$page) {
				return ['id' => $page_id, 'title' => null, 'permalink' => null];
			}
			return [
				'id' => (int) $page_id,
				'title' => $page->post_title,
				'permalink' => get_permalink($page_id),
			];
		};

		return [
			'cart_page' => $get_page_details($cart_page_id),
			'checkout_page' => $get_page_details($checkout_page_id),
			'myaccount_page' => $get_page_details($myaccount_page_id),
			'terms_page' => $get_page_details($terms_page_id),
			'secure_checkout' => [
				'force_ssl_checkout' => get_option('woocommerce_force_ssl_checkout') === 'yes',
				'description' => 'Force SSL (HTTPS) on the checkout pages (an SSL Certificate is required)',
			],
			'checkout_endpoints' => [
				'pay' => get_option('woocommerce_checkout_pay_endpoint', 'order-pay'),
				'order_received' => get_option('woocommerce_checkout_order_received_endpoint', 'order-received'),
				'add_payment_method' => get_option('woocommerce_checkout_add_payment_method_endpoint', 'add-payment-method'),
				'delete_payment_method' => get_option('woocommerce_checkout_delete_payment_method_endpoint', 'delete-payment-method'),
				'set_default_payment_method' => get_option('woocommerce_checkout_set_default_payment_method_endpoint', 'set-default-payment-method'),
			],
			'account_endpoints' => [
				'orders' => get_option('woocommerce_myaccount_orders_endpoint', 'orders'),
				'view_order' => get_option('woocommerce_myaccount_view_order_endpoint', 'view-order'),
				'downloads' => get_option('woocommerce_myaccount_downloads_endpoint', 'downloads'),
				'edit_account' => get_option('woocommerce_myaccount_edit_account_endpoint', 'edit-account'),
				'edit_address' => get_option('woocommerce_myaccount_edit_address_endpoint', 'edit-address'),
				'payment_methods' => get_option('woocommerce_myaccount_payment_methods_endpoint', 'payment-methods'),
				'lost_password' => get_option('woocommerce_myaccount_lost_password_endpoint', 'lost-password'),
				'customer_logout' => get_option('woocommerce_logout_endpoint', 'customer-logout'),
			],
		];
	}

	private function update_pages_settings($params) {
		$allowed_keys = [
			'cart_page_id', 'checkout_page_id', 'myaccount_page_id', 'terms_page_id',
			'force_ssl_checkout',
			'checkout_pay_endpoint', 'checkout_order_received_endpoint',
			'checkout_add_payment_method_endpoint', 'checkout_delete_payment_method_endpoint',
			'checkout_set_default_payment_method_endpoint',
			'myaccount_orders_endpoint', 'myaccount_view_order_endpoint',
			'myaccount_downloads_endpoint', 'myaccount_edit_account_endpoint',
			'myaccount_edit_address_endpoint', 'myaccount_payment_methods_endpoint',
			'myaccount_lost_password_endpoint', 'logout_endpoint',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				// Handle boolean for force_ssl_checkout
				if ($key === 'force_ssl_checkout') {
					update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
				} else {
					update_option('woocommerce_' . $key, sanitize_text_field($value));
				}
			}
		}

		return true;
	}

	// REST API Settings
	private function get_rest_api_settings() {
		global $wpdb;
		
		// Check if table exists
		$table_name = $wpdb->prefix . 'woocommerce_api_keys';
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
			return [
				'enabled' => get_option('woocommerce_api_enabled') === 'yes',
				'api_keys' => [],
			];
		}
		
		// Get available columns
		$columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
		$select_columns = ['key_id', 'user_id', 'description', 'permissions'];
		
		// Only include columns that exist
		if (in_array('consumer_key', $columns)) {
			$select_columns[] = 'consumer_key';
		}
		if (in_array('last_access', $columns)) {
			$select_columns[] = 'last_access';
		}
		
		$keys = $wpdb->get_results(
			"SELECT " . implode(', ', $select_columns) . "
			FROM {$table_name}
			ORDER BY key_id DESC"
		);

		$formatted_keys = [];
		foreach ($keys as $key) {
			$user = get_user_by('id', $key->user_id);
			$formatted_key = [
				'key_id' => (int) $key->key_id,
				'user_id' => (int) $key->user_id,
				'user_name' => $user ? $user->display_name : null,
				'description' => $key->description,
				'permissions' => $key->permissions,
			];
			
			// Add consumer_key if available (truncated for security)
			if (isset($key->consumer_key)) {
				$formatted_key['consumer_key'] = substr($key->consumer_key, 0, 7) . '...';
			}
			
			// Add last_access if available
			if (isset($key->last_access)) {
				$formatted_key['last_access'] = $key->last_access;
			}
			
			$formatted_keys[] = $formatted_key;
		}

		return [
			'enabled' => get_option('woocommerce_api_enabled') === 'yes',
			'api_keys' => $formatted_keys,
		];
	}

	private function update_rest_api_settings($params) {
		$allowed_keys = ['api_enabled'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
			}
		}

		return true;
	}

	// Webhooks Settings
	private function get_webhooks_settings() {
		// WooCommerce stores webhooks as posts with post_type 'shop_webhook'
		$webhook_posts = get_posts([
			'post_type' => 'shop_webhook',
			'posts_per_page' => -1,
			'post_status' => 'any',
		]);

		$formatted_webhooks = [];
		foreach ($webhook_posts as $webhook_post) {
			$status = get_post_meta($webhook_post->ID, '_status', true);
			$delivery_url = get_post_meta($webhook_post->ID, '_delivery_url', true);
			$secret = get_post_meta($webhook_post->ID, '_secret', true);
			$topic = get_post_meta($webhook_post->ID, '_topic', true);
			$event = get_post_meta($webhook_post->ID, '_event', true);

			$formatted_webhooks[] = [
				'webhook_id' => (int) $webhook_post->ID,
				'status' => $status ?: 'disabled',
				'name' => $webhook_post->post_title,
				'user_id' => (int) $webhook_post->post_author,
				'date_created' => $webhook_post->post_date,
				'date_modified' => $webhook_post->post_modified,
				'delivery_url' => $delivery_url,
				'secret' => $secret ? substr($secret, 0, 10) . '...' : null,
				'topic' => $topic,
				'event' => $event,
			];
		}

		return [
			'webhooks' => $formatted_webhooks,
			'total' => count($formatted_webhooks),
		];
	}

	private function update_webhooks_settings($params) {
		// Webhook updates are typically done through the webhooks API
		// This is mainly for reading settings
		return true;
	}

	// Legacy API Settings
	private function get_legacy_api_settings() {
		global $wpdb;
		
		// Check if legacy API keys table exists
		$legacy_keys = [];
		$table_name = $wpdb->prefix . 'woocommerce_api_keys';
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
			// Get available columns
			$columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
			$select_columns = ['key_id', 'user_id', 'description', 'permissions'];
			
			// Only include columns that exist
			if (in_array('consumer_key', $columns)) {
				$select_columns[] = 'consumer_key';
			}
			
			$legacy_keys_query = $wpdb->get_results(
				"SELECT " . implode(', ', $select_columns) . "
				FROM $table_name
				WHERE permissions = 'read_write' OR permissions = 'read'
				ORDER BY key_id DESC"
			);

			foreach ($legacy_keys_query as $key) {
				$formatted_key = [
					'key_id' => (int) $key->key_id,
					'user_id' => (int) $key->user_id,
					'description' => $key->description,
					'permissions' => $key->permissions,
				];
				
				// Add consumer_key if available (truncated for security)
				if (isset($key->consumer_key)) {
					$formatted_key['consumer_key'] = substr($key->consumer_key, 0, 7) . '...';
				}
				
				$legacy_keys[] = $formatted_key;
			}
		}

		return [
			'legacy_api_enabled' => get_option('woocommerce_enable_legacy_api') === 'yes',
			'legacy_api_keys' => $legacy_keys,
			'note' => 'Legacy REST API (v2) is deprecated. Use the REST API (v3) instead.',
		];
	}

	private function update_legacy_api_settings($params) {
		$allowed_keys = ['enable_legacy_api'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
			}
		}

		return true;
	}

	// WooCommerce.com Settings
	private function get_woocommerce_com_settings() {
		return [
			'connected' => get_option('woocommerce_helper_connected') === 'yes',
			'auth_user' => get_option('_wc_helper_auth_user'),
			'auth_token' => get_option('_wc_helper_auth_token') ? '***' : null,
			'helper_host' => get_option('_wc_helper_host'),
			'helper_port' => get_option('_wc_helper_port'),
			'helper_nonce' => get_option('_wc_helper_nonce'),
			'subscriptions_active' => get_option('woocommerce_subscriptions_active') === 'yes',
		];
	}

	private function update_woocommerce_com_settings($params) {
		// WooCommerce.com connection is typically managed through the helper UI
		// This is mainly for reading connection status
		return true;
	}

	// Blueprint Settings (Beta)
	private function get_blueprint_settings() {
		return [
			'enabled' => get_option('woocommerce_blueprint_enabled') === 'yes',
			'beta_note' => 'Blueprint is a beta feature. Use with caution.',
			'blueprint_data' => get_option('woocommerce_blueprint_data', []),
		];
	}

	private function update_blueprint_settings($params) {
		$allowed_keys = ['blueprint_enabled', 'blueprint_data'];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				if ($key === 'blueprint_enabled') {
					update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
				} else {
					update_option('woocommerce_' . $key, $value);
				}
			}
		}

		return true;
	}

	// Features Settings
	private function get_features_settings() {
		// Get WooCommerce feature flags
		$features = [
			'cart_ajax' => get_option('woocommerce_enable_ajax_add_to_cart') === 'yes',
			'coupons' => get_option('woocommerce_enable_coupons') === 'yes',
			'calculations' => get_option('woocommerce_calc_shipping') === 'yes',
			'guest_checkout' => get_option('woocommerce_enable_guest_checkout') === 'yes',
			'checkout_login_reminder' => get_option('woocommerce_enable_checkout_login_reminder') === 'yes',
			'product_reviews' => get_option('woocommerce_enable_reviews') === 'yes',
			'product_reviews_rating_required' => get_option('woocommerce_review_rating_required') === 'yes',
			'myaccount_registration' => get_option('woocommerce_enable_myaccount_registration') === 'yes',
			'checkout_phone' => get_option('woocommerce_checkout_phone_field') === 'required',
			'account_downloads' => get_option('woocommerce_enable_customer_downloads') === 'yes',
			'product_stock' => get_option('woocommerce_manage_stock') === 'yes',
			'product_images' => get_option('woocommerce_enable_product_images') === 'yes',
			'store_notice' => get_option('woocommerce_demo_store') === 'yes',
		];

		return [
			'features' => $features,
			'feature_flags' => apply_filters('woocommerce_feature_flags', []),
		];
	}

	private function update_features_settings($params) {
		$allowed_keys = [
			'enable_ajax_add_to_cart',
			'enable_coupons',
			'calc_shipping',
			'enable_guest_checkout',
			'enable_checkout_login_reminder',
			'enable_reviews',
			'review_rating_required',
			'enable_myaccount_registration',
			'checkout_phone_field',
			'enable_customer_downloads',
			'manage_stock',
			'enable_product_images',
			'demo_store',
		];

		foreach ($params as $key => $value) {
			if (in_array($key, $allowed_keys)) {
				if (is_bool($value)) {
					update_option('woocommerce_' . $key, $value ? 'yes' : 'no');
				} else {
					update_option('woocommerce_' . $key, sanitize_text_field($value));
				}
			}
		}

		return true;
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

