<?php

class RESTBridge_Shipping_Zones_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones', [
			'methods' => 'GET',
			'callback' => [$this, 'get_zones'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones', [
			'methods' => 'POST',
			'callback' => [$this, 'create_zone'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_zone'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_zone'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_zone'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<zone_id>\d+)/methods', [
			'methods' => 'GET',
			'callback' => [$this, 'get_zone_methods'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<zone_id>\d+)/methods', [
			'methods' => 'POST',
			'callback' => [$this, 'add_zone_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<zone_id>\d+)/methods/(?P<instance_id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_zone_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<zone_id>\d+)/methods/(?P<instance_id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_zone_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/zones/(?P<zone_id>\d+)/methods/(?P<instance_id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_zone_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods', [
			'methods' => 'GET',
			'callback' => [$this, 'get_available_methods'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_zones() {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zones_data = WC_Shipping_Zones::get_zones();
		$formatted_zones = [];

		// get_zones() returns array with zone_id as key and zone data array as value
		// Get zone object for each zone to format properly
		foreach ($zones_data as $zone_id => $zone_data) {
			// Skip if zone_id is 0 (Rest of World) - we'll add it separately
			if ($zone_id == 0) {
				continue;
			}
			
			// Get zone object to format
			$zone = WC_Shipping_Zones::get_zone($zone_id);
			if ($zone && is_object($zone)) {
				$formatted_zone = $this->format_zone($zone);
				if (isset($formatted_zone['name']) && $formatted_zone['name'] != 'Invalid Zone') {
					$formatted_zones[] = $formatted_zone;
				}
			}
		}

		// Add "Rest of the World" zone (zone ID 0)
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world && is_object($rest_of_world)) {
			$formatted_zone = $this->format_zone($rest_of_world);
			if (isset($formatted_zone['name']) && $formatted_zone['name'] != 'Invalid Zone') {
				$formatted_zones[] = $formatted_zone;
			}
		}

		return rest_ensure_response([
			'zones' => $formatted_zones,
			'total' => count($formatted_zones),
		]);
	}

	public function get_zone(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['id'];
		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_zone($zone));
	}

	public function create_zone(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		if (!isset($params['zone_name'])) {
			return new WP_Error('missing_zone_name', 'Zone name is required', ['status' => 400]);
		}

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name(sanitize_text_field($params['zone_name']));

		if (isset($params['zone_order'])) {
			$zone->set_zone_order((int) $params['zone_order']);
		}

		$zone->save();

		// Add locations
		if (isset($params['locations']) && is_array($params['locations'])) {
			$zone->clear_locations();
			foreach ($params['locations'] as $location) {
				$zone->add_location(
					sanitize_text_field($location['code']),
					sanitize_text_field($location['type'])
				);
			}
			$zone->save();
		}

		return rest_ensure_response($this->format_zone($zone), 201);
	}

	public function update_zone(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['id'];
		$params = $request->get_json_params();

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		if (isset($params['zone_name'])) {
			$zone->set_zone_name(sanitize_text_field($params['zone_name']));
		}

		if (isset($params['zone_order'])) {
			$zone->set_zone_order((int) $params['zone_order']);
		}

		if (isset($params['locations']) && is_array($params['locations'])) {
			$zone->clear_locations();
			foreach ($params['locations'] as $location) {
				$zone->add_location(
					sanitize_text_field($location['code']),
					sanitize_text_field($location['type'])
				);
			}
		}

		$zone->save();

		return rest_ensure_response($this->format_zone($zone));
	}

	public function delete_zone(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['id'];

		if ($zone_id === 0) {
			return new WP_Error('cannot_delete', 'Cannot delete Rest of the World zone', ['status' => 400]);
		}

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		$zone->delete();

		return rest_ensure_response(['deleted' => true, 'id' => $zone_id]);
	}

	public function get_zone_methods(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['zone_id'];
		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		$methods = $zone->get_shipping_methods(true);
		$formatted_methods = [];

		foreach ($methods as $instance_id => $method) {
			$formatted_methods[] = $this->format_method($method, $instance_id);
		}

		return rest_ensure_response([
			'zone_id' => $zone_id,
			'methods' => $formatted_methods,
			'total' => count($formatted_methods),
		]);
	}

	public function add_zone_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['zone_id'];
		$params = $request->get_json_params();

		if (!isset($params['method_id'])) {
			return new WP_Error('missing_method_id', 'Shipping method ID is required', ['status' => 400]);
		}

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		$instance_id = $zone->add_shipping_method(sanitize_text_field($params['method_id']));

		if (!$instance_id) {
			return new WP_Error('method_add_failed', 'Failed to add shipping method', ['status' => 500]);
		}

		// Save zone first to create the method instance in database
		$zone->save();
		
		// Now update settings using the EXACT same pattern as update_zone_method (which works)
		// Clear cache and reload to get fresh zone object
		wp_cache_delete('shipping_zone_' . $zone_id, 'woocommerce');
		$zone = WC_Shipping_Zones::get_zone($zone_id);
		
		if (!$zone) {
			return new WP_Error('zone_not_found', 'Failed to reload zone after adding method', ['status' => 500]);
		}

		// Get method using EXACT same pattern as update_zone_method
		$methods = $zone->get_shipping_methods(true);
		if (!is_array($methods) || !isset($methods[$instance_id])) {
			return new WP_Error('method_not_found', 'Failed to retrieve added shipping method', ['status' => 500]);
		}

		$method = $methods[$instance_id];

		// Update settings - Directly update instance_settings AND use update_option()
		// For newly added methods, we need both approaches to ensure persistence
		if (isset($params['settings']) && is_array($params['settings'])) {
			// 1. Directly update instance_settings property (this is what zone->save() uses)
			if (property_exists($method, 'instance_settings') && is_array($method->instance_settings)) {
				foreach ($params['settings'] as $key => $value) {
					$method->instance_settings[$key] = sanitize_text_field($value);
				}
			}
			
			// 2. Also call update_option() for consistency with update_zone_method
			foreach ($params['settings'] as $key => $value) {
				$method->update_option($key, sanitize_text_field($value));
			}
		}

		// Save zone - this persists the instance_settings changes
		$zone->save();
		
		// Force reload to get fresh data
		wp_cache_delete('shipping_zone_' . $zone_id, 'woocommerce');
		$zone = WC_Shipping_Zones::get_zone($zone_id);
		$methods = $zone->get_shipping_methods(true);
		if (is_array($methods) && isset($methods[$instance_id])) {
			$method = $methods[$instance_id];
		}
		
		// Clear all caches (same as update_zone_method pattern)
		wp_cache_delete('shipping_zone_' . $zone_id, 'woocommerce');
		wp_cache_delete('shipping_zones', 'woocommerce');
		delete_transient('wc_shipping_zone_' . $zone_id);
		delete_transient('wc_shipping_zones');
		
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::get_transient_version('shipping', true);
		}
		
		// Reload zone to get fresh method data
		$reloaded_zone = WC_Shipping_Zones::get_zone($zone_id);
		if ($reloaded_zone) {
			$methods = $reloaded_zone->get_shipping_methods(true);
			if (is_array($methods) && isset($methods[$instance_id])) {
				$method = $methods[$instance_id];
			}
		}

		return rest_ensure_response([
			'success' => true,
			'instance_id' => $instance_id,
			'method' => $method ? $this->format_method($method, $instance_id) : null,
		], 201);
	}

	public function get_zone_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['zone_id'];
		$instance_id = (int) $request['instance_id'];

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		// Get method from get_shipping_methods array
		$methods = $zone->get_shipping_methods(true);
		if (!is_array($methods) || !isset($methods[$instance_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $methods[$instance_id];
		return rest_ensure_response($this->format_method($method, $instance_id));
	}

	public function update_zone_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['zone_id'];
		$instance_id = (int) $request['instance_id'];
		$params = $request->get_json_params();

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		// Get method from get_shipping_methods array
		$methods = $zone->get_shipping_methods(true);
		if (!is_array($methods) || !isset($methods[$instance_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $methods[$instance_id];

		// Update enabled status
		if (isset($params['enabled'])) {
			$method->update_option('enabled', $params['enabled'] ? 'yes' : 'no');
		}

		// Update method title
		if (isset($params['title'])) {
			$method->update_option('title', sanitize_text_field($params['title']));
		}

		// Update other settings
		if (isset($params['settings']) && is_array($params['settings'])) {
			foreach ($params['settings'] as $key => $value) {
				$method->update_option($key, sanitize_text_field($value));
			}
		}

		$zone->save();

		return rest_ensure_response([
			'success' => true,
			'method' => $this->format_method($method, $instance_id),
		]);
	}

	public function delete_zone_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$zone_id = (int) $request['zone_id'];
		$instance_id = (int) $request['instance_id'];

		$zone = WC_Shipping_Zones::get_zone($zone_id);

		if (!$zone) {
			return new WP_Error('zone_not_found', 'Shipping zone not found', ['status' => 404]);
		}

		// Check if method exists before deleting
		$methods = $zone->get_shipping_methods(true);
		if (!is_array($methods) || !isset($methods[$instance_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$zone->delete_shipping_method($instance_id);

		return rest_ensure_response(['deleted' => true, 'instance_id' => $instance_id]);
	}

	public function get_available_methods() {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$shipping_methods = WC()->shipping()->get_shipping_methods();
		$formatted_methods = [];

		foreach ($shipping_methods as $method_id => $method) {
			$formatted_methods[] = [
				'id' => $method_id,
				'title' => $method->get_method_title(),
				'description' => $method->get_method_description(),
				'enabled' => $method->is_enabled(),
			];
		}

		return rest_ensure_response([
			'methods' => $formatted_methods,
			'total' => count($formatted_methods),
		]);
	}

	private function format_zone($zone) {
		if (!is_object($zone)) {
			return [
				'id' => 0,
				'name' => 'Invalid Zone',
				'order' => 0,
				'locations' => [],
				'methods' => [],
				'method_count' => 0,
			];
		}
		
		// Check if zone has basic required methods - be lenient
		if (!method_exists($zone, 'get_id')) {
			return [
				'id' => 0,
				'name' => 'Invalid Zone',
				'order' => 0,
				'locations' => [],
				'methods' => [],
				'method_count' => 0,
			];
		}

		$locations = [];
		if (method_exists($zone, 'get_locations')) {
			$zone_locations = $zone->get_locations();
			
			if (is_array($zone_locations)) {
				foreach ($zone_locations as $location) {
					// Handle both object and array formats
					if (is_object($location)) {
						$locations[] = [
							'code' => isset($location->code) ? $location->code : '',
							'type' => isset($location->type) ? $location->type : '',
						];
					} elseif (is_array($location)) {
						$locations[] = [
							'code' => isset($location['code']) ? $location['code'] : '',
							'type' => isset($location['type']) ? $location['type'] : '',
						];
					}
				}
			}
		}

		$methods = [];
		if (method_exists($zone, 'get_shipping_methods')) {
			$zone_methods = $zone->get_shipping_methods(true);
			
			if (is_array($zone_methods)) {
				foreach ($zone_methods as $instance_id => $method) {
					$methods[] = $this->format_method($method, $instance_id);
				}
			}
		}

		return [
			'id' => method_exists($zone, 'get_id') ? $zone->get_id() : 0,
			'name' => method_exists($zone, 'get_zone_name') ? $zone->get_zone_name() : '',
			'order' => method_exists($zone, 'get_zone_order') ? $zone->get_zone_order() : 0,
			'locations' => $locations,
			'methods' => $methods,
			'method_count' => count($methods),
		];
	}

	private function format_method($method, $instance_id) {
		if (!is_object($method)) {
			return [
				'instance_id' => $instance_id,
				'method_id' => '',
				'method_title' => '',
				'title' => '',
				'enabled' => false,
				'settings' => [],
			];
		}

		$settings = [];
		
		// IMPORTANT: update_option() writes to $method->settings, not instance_settings!
		// Check $method->settings first (this is where update_option() stores values)
		if (property_exists($method, 'settings') && is_array($method->settings)) {
			foreach ($method->settings as $key => $value) {
				// Skip instance_id and other non-setting keys
				if (in_array($key, ['instance_id', 'class_cost_books'])) {
					continue;
				}
				if ($value !== null && $value !== '') {
					$settings[$key] = $value;
				} elseif ($key === 'cost' && $value === '0') {
					$settings[$key] = $value;
				}
			}
		}
		
		// Also check instance_settings as fallback
		if (property_exists($method, 'instance_settings') && is_array($method->instance_settings)) {
			foreach ($method->instance_settings as $key => $value) {
				// Only use if not already set from settings property
				if (!isset($settings[$key])) {
					if ($value !== null && $value !== '') {
						$settings[$key] = $value;
					} elseif ($key === 'cost' && $value === '0') {
						$settings[$key] = $value;
					}
				}
			}
		}
		
		// Try get_instance_option for methods that support it
		if (method_exists($method, 'get_instance_option_keys') && method_exists($method, 'get_instance_option')) {
			$option_keys = $method->get_instance_option_keys();
			if (is_array($option_keys)) {
				$option_keys = array_keys($option_keys);
				foreach ($option_keys as $key) {
					// Only use if not already set
					if (!isset($settings[$key])) {
						$value = $method->get_instance_option($key);
						if ($value !== null && $value !== '') {
							$settings[$key] = $value;
						}
					}
				}
			}
		}
		
		// Try get_option as final fallback
		if (method_exists($method, 'get_option')) {
			$common_keys = ['title', 'cost', 'enabled', 'tax_status', 'class_cost', 'no_class_cost', 'type', 'requires', 'min_amount'];
			foreach ($common_keys as $key) {
				if (!isset($settings[$key])) {
					$value = $method->get_option($key);
					if ($value !== null && $value !== '') {
						$settings[$key] = $value;
					} elseif ($key === 'cost' && $value === '0') {
						$settings[$key] = $value;
					}
				}
			}
		}

		return [
			'instance_id' => $instance_id,
			'method_id' => isset($method->id) ? $method->id : '',
			'method_title' => method_exists($method, 'get_method_title') ? $method->get_method_title() : '',
			'title' => method_exists($method, 'get_title') ? $method->get_title() : '',
			'enabled' => method_exists($method, 'is_enabled') ? $method->is_enabled() : false,
			'settings' => $settings,
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

