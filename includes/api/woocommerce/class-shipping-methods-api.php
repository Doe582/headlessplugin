<?php

class RESTBridge_Shipping_Methods_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods', [
			'methods' => 'GET',
			'callback' => [$this, 'get_all_methods'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/(?P<id>[a-zA-Z0-9_-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/(?P<id>[a-zA-Z0-9_-]+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_method'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/(?P<id>[a-zA-Z0-9_-]+)/instances', [
			'methods' => 'GET',
			'callback' => [$this, 'get_method_instances'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/(?P<id>[a-zA-Z0-9_-]+)/settings', [
			'methods' => 'GET',
			'callback' => [$this, 'get_method_settings'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/(?P<id>[a-zA-Z0-9_-]+)/settings', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_method_settings'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/instances', [
			'methods' => 'GET',
			'callback' => [$this, 'get_all_instances'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/shipping/methods/instances/(?P<instance_id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_instance'],
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
	 * Get all available shipping methods
	 */
	public function get_all_methods(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$shipping_methods = WC()->shipping()->get_shipping_methods();
		$formatted_methods = [];

		foreach ($shipping_methods as $method_id => $method) {
			$formatted_methods[] = $this->format_method($method, $method_id);
		}

		return rest_ensure_response([
			'shipping_methods' => $formatted_methods,
			'total' => count($formatted_methods),
		]);
	}

	/**
	 * Get a specific shipping method by ID
	 */
	public function get_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$method_id = sanitize_text_field($request['id']);
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		if (!isset($shipping_methods[$method_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $shipping_methods[$method_id];

		return rest_ensure_response($this->format_method($method, $method_id, true));
	}

	/**
	 * Update shipping method (enable/disable, global settings, and instance settings)
	 */
	public function update_method(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$method_id = sanitize_text_field($request['id']);
		$params = $request->get_json_params();
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		if (!isset($shipping_methods[$method_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $shipping_methods[$method_id];

		// Update global enabled status
		if (isset($params['enabled'])) {
			$method->enabled = $params['enabled'] ? 'yes' : 'no';
			update_option('woocommerce_' . $method_id . '_enabled', $method->enabled);
		}

		// Update instance settings if provided (cost, title, etc. are instance-specific)
		// Also update instance enabled if provided at top level
		if ($this->has_instance_fields($params) || isset($params['enabled'])) {
			$this->update_all_instances($method_id, $params);
		}

		// Update global settings (non-instance settings)
		$this->update_global_settings($method, $method_id, $params);

		return rest_ensure_response([
			'success' => true,
			'message' => 'Shipping method updated successfully',
			'shipping_method' => $this->format_method($method, $method_id, true),
		]);
	}

	/**
	 * Check if params contain instance-specific fields
	 */
	private function has_instance_fields($params) {
		$instance_fields = ['cost', 'title', 'tax_status', 'class_cost', 'no_class_cost', 'type'];
		
		if (!isset($params['settings']) || !is_array($params['settings'])) {
			return false;
		}

		return (bool) array_intersect(array_keys($params['settings']), $instance_fields);
	}

	/**
	 * Update all instances - only updates instance settings from $params['settings']
	 */
	private function update_all_instances($method_id, $params) {
		$zones_data = WC_Shipping_Zones::get_zones();
		
		// Normalize zones to objects (same pattern as get_zones in shipping-zones-api)
		$zones = [];
		foreach ($zones_data as $zone_id => $zone_data) {
			$zone = WC_Shipping_Zones::get_zone($zone_id);
			if ($zone && is_object($zone)) {
				$zones[] = $zone;
			}
		}
		
		// Add "Rest of the World" zone (zone ID 0)
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world && is_object($rest_of_world)) {
			$zones[] = $rest_of_world;
		}

		foreach ($zones as $zone) {
			if (!is_object($zone) || !method_exists($zone, 'get_shipping_methods')) {
				continue;
			}

			$methods = $zone->get_shipping_methods(true);
			if (!is_array($methods)) {
				continue;
			}

			$zone_changed = false;
			foreach ($methods as $instance_id => $instance) {
				if (!is_object($instance) || 
					!isset($instance->id) || 
					$instance->id !== $method_id) {
					continue;
				}

				// Try to get method via zone->get_shipping_method (like update_zone_method does)
				// This ensures we have the correct object reference that will persist
				$method = null;
				if (method_exists($zone, 'get_shipping_method')) {
					$method = $zone->get_shipping_method($instance_id);
				}
				
				// Fallback to instance from loop if get_shipping_method doesn't exist
				if (!$method) {
					$method = $instance;
				}

				// Update instance enabled if provided at top level
				if (isset($params['enabled'])) {
					$enabled_value = $params['enabled'] ? 'yes' : 'no';
					$method->update_option('enabled', $enabled_value);
					$zone_changed = true;
				}

				// Only update instance settings from $params['settings']
				if (isset($params['settings']) && is_array($params['settings'])) {
					foreach ($params['settings'] as $key => $value) {
						$sanitized_value = sanitize_text_field($value);
						$method->update_option($key, $sanitized_value);
						$zone_changed = true;
					}
				}
			}

			if ($zone_changed) {
				// Save zone - this persists all instance changes (EXACT same as update_zone_method)
				$zone->save();
				
				// Clear all caches
				$zone_id = $zone->get_id();
				wp_cache_delete('shipping_zone_' . $zone_id, 'woocommerce');
				wp_cache_delete('shipping_zones', 'woocommerce');
				delete_transient('wc_shipping_zone_' . $zone_id);
				delete_transient('wc_shipping_zones');
			}
		}

		// Clear cache
		if (class_exists('WC_Cache_Helper')) {
			WC_Cache_Helper::get_transient_version('shipping', true);
		}
		delete_transient('wc_shipping_zones');
	}

	/**
	 * Update global settings (non-instance settings)
	 */
	private function update_global_settings($method, $method_id, $params) {
		if (!isset($params['settings']) || !is_array($params['settings'])) {
			return;
		}

		$instance_fields = ['cost', 'title', 'tax_status', 'class_cost', 'no_class_cost', 'type'];

		foreach ($params['settings'] as $key => $value) {
			if (in_array($key, $instance_fields)) {
				continue;
			}

			if (method_exists($method, 'update_option')) {
				$method->update_option($key, sanitize_text_field($value));
			} else {
				update_option('woocommerce_' . $method_id . '_' . $key, sanitize_text_field($value));
			}
		}
	}

	/**
	 * Get all instances of a shipping method across all zones
	 */
	public function get_method_instances(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$method_id = sanitize_text_field($request['id']);
		$instances = [];
		$zones = WC_Shipping_Zones::get_zones();
		
		// Add "Rest of the World" zone
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world) {
			$zones[] = $rest_of_world;
		}

		foreach ($zones as $zone_key => $zone_data) {
			$zone = null;
			
			// Handle zone data - could be array or object
			if (is_object($zone_data)) {
				// Already a zone object
				$zone = $zone_data;
			} elseif (is_array($zone_data) && isset($zone_data['id'])) {
				// Array with zone data, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_data['id']);
			} elseif (is_numeric($zone_key)) {
				// Zone ID is the key, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_key);
			}
			
			if (!$zone || !is_object($zone)) {
				continue;
			}

			if (method_exists($zone, 'get_shipping_methods')) {
				$zone_methods = $zone->get_shipping_methods(true);
				if (is_array($zone_methods)) {
					foreach ($zone_methods as $instance_id => $method) {
						if (is_object($method) && isset($method->id) && $method->id === $method_id) {
							$instances[] = [
								'instance_id' => $instance_id,
								'zone_id' => $zone->get_id(),
								'zone_name' => $zone->get_zone_name(),
								'method_id' => $method->id,
								'method_title' => $method->get_method_title(),
								'title' => $method->get_title(),
								'enabled' => $method->is_enabled(),
								'settings' => $this->get_method_instance_settings($method),
							];
						}
					}
				}
			}
		}

		return rest_ensure_response([
			'method_id' => $method_id,
			'instances' => $instances,
			'total' => count($instances),
		]);
	}

	/**
	 * Get global settings for a shipping method
	 */
	public function get_method_settings(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$method_id = sanitize_text_field($request['id']);
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		if (!isset($shipping_methods[$method_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $shipping_methods[$method_id];
		$settings = [];

		// Get form fields if method supports them
		if (method_exists($method, 'get_form_fields')) {
			$form_fields = $method->get_form_fields();
			foreach ($form_fields as $key => $field) {
				$value = method_exists($method, 'get_option') ? $method->get_option($key) : get_option('woocommerce_' . $method_id . '_' . $key, '');
				$settings[$key] = [
					'value' => $value,
					'title' => isset($field['title']) ? $field['title'] : $key,
					'description' => isset($field['description']) ? $field['description'] : '',
					'type' => isset($field['type']) ? $field['type'] : 'text',
					'default' => isset($field['default']) ? $field['default'] : '',
				];
			}
		} else {
			// Fallback: get enabled status
			$enabled = get_option('woocommerce_' . $method_id . '_enabled', 'no');
			$settings['enabled'] = [
				'value' => $enabled,
				'title' => 'Enabled',
				'type' => 'checkbox',
			];
		}

		return rest_ensure_response([
			'method_id' => $method_id,
			'settings' => $settings,
		]);
	}

	/**
	 * Update global settings for a shipping method
	 */
	public function update_method_settings(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$method_id = sanitize_text_field($request['id']);
		$params = $request->get_json_params();
		$shipping_methods = WC()->shipping()->get_shipping_methods();

		if (!isset($shipping_methods[$method_id])) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

		$method = $shipping_methods[$method_id];
		$updated_settings = [];

		if (isset($params['settings']) && is_array($params['settings'])) {
			foreach ($params['settings'] as $key => $value) {
				if (method_exists($method, 'update_option')) {
					$method->update_option($key, sanitize_text_field($value));
				} else {
					update_option('woocommerce_' . $method_id . '_' . $key, sanitize_text_field($value));
				}
				$updated_settings[$key] = $value;
			}
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Shipping method settings updated successfully',
			'method_id' => $method_id,
			'settings' => $updated_settings,
		]);
	}

	/**
	 * Get all shipping method instances across all zones
	 */
	public function get_all_instances(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		$method_id_filter = isset($params['method_id']) ? sanitize_text_field($params['method_id']) : null;
		
		$all_instances = [];
		$zones = WC_Shipping_Zones::get_zones();
		
		// Add "Rest of the World" zone
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world) {
			$zones[] = $rest_of_world;
		}

		foreach ($zones as $zone_key => $zone_data) {
			$zone = null;
			
			// Handle zone data - could be array or object
			if (is_object($zone_data)) {
				// Already a zone object
				$zone = $zone_data;
			} elseif (is_array($zone_data) && isset($zone_data['id'])) {
				// Array with zone data, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_data['id']);
			} elseif (is_numeric($zone_key)) {
				// Zone ID is the key, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_key);
			}
			
			if (!$zone || !is_object($zone)) {
				continue;
			}

			if (method_exists($zone, 'get_shipping_methods')) {
				$zone_methods = $zone->get_shipping_methods(true);
				if (is_array($zone_methods)) {
					foreach ($zone_methods as $instance_id => $method) {
						// Filter by method_id if provided
						if ($method_id_filter && is_object($method) && isset($method->id) && $method->id !== $method_id_filter) {
							continue;
						}

						if (is_object($method)) {
							$all_instances[] = [
								'instance_id' => $instance_id,
								'zone_id' => $zone->get_id(),
								'zone_name' => $zone->get_zone_name(),
								'method_id' => $method->id,
								'method_title' => $method->get_method_title(),
								'title' => $method->get_title(),
								'enabled' => $method->is_enabled(),
								'settings' => $this->get_method_instance_settings($method),
							];
						}
					}
				}
			}
		}

		return rest_ensure_response([
			'instances' => $all_instances,
			'total' => count($all_instances),
		]);
	}

	/**
	 * Get a specific shipping method instance by instance ID
	 */
	public function get_instance(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$instance_id = (int) $request['instance_id'];
		$zones = WC_Shipping_Zones::get_zones();
		
		// Add "Rest of the World" zone
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world) {
			$zones[] = $rest_of_world;
		}

		foreach ($zones as $zone_key => $zone_data) {
			$zone = null;
			
			// Handle zone data - could be array or object
			if (is_object($zone_data)) {
				// Already a zone object
				$zone = $zone_data;
			} elseif (is_array($zone_data) && isset($zone_data['id'])) {
				// Array with zone data, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_data['id']);
			} elseif (is_numeric($zone_key)) {
				// Zone ID is the key, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_key);
			}
			
			if (!$zone || !is_object($zone)) {
				continue;
			}

			if (method_exists($zone, 'get_shipping_methods')) {
				$zone_methods = $zone->get_shipping_methods(true);
				if (is_array($zone_methods) && isset($zone_methods[$instance_id])) {
					$method = $zone_methods[$instance_id];
					if (is_object($method)) {
						return rest_ensure_response([
							'instance_id' => $instance_id,
							'zone_id' => $zone->get_id(),
							'zone_name' => $zone->get_zone_name(),
							'method_id' => $method->id,
							'method_title' => $method->get_method_title(),
							'title' => $method->get_title(),
							'enabled' => $method->is_enabled(),
							'settings' => $this->get_method_instance_settings($method),
						]);
					}
				}
			}
		}

		return new WP_Error('instance_not_found', 'Shipping method instance not found', ['status' => 404]);
	}

	/**
	 * Format shipping method for response
	 */
	private function format_method($method, $method_id, $detailed = false) {
		$formatted = [
			'id' => $method_id,
			'title' => $method->get_method_title(),
			'description' => $method->get_method_description(),
			'enabled' => $method->is_enabled(),
		];

		if ($detailed) {
			$formatted['supports'] = $this->get_method_supports($method);
			$formatted['instance_count'] = $this->count_method_instances($method_id);
		}

		return $formatted;
	}

	/**
	 * Get method instance settings
	 */
	private function get_method_instance_settings($method) {
		$settings = [];
		
		if (method_exists($method, 'get_instance_option_keys')) {
			$option_keys = array_keys($method->get_instance_option_keys());
			foreach ($option_keys as $key) {
				if (method_exists($method, 'get_instance_option')) {
					$settings[$key] = $method->get_instance_option($key);
				} elseif (method_exists($method, 'get_option')) {
					$settings[$key] = $method->get_option($key);
				}
			}
		} else {
			// Fallback: try to get common settings
			$common_keys = ['title', 'cost', 'enabled', 'tax_status'];
			foreach ($common_keys as $key) {
				if (method_exists($method, 'get_option')) {
					$value = $method->get_option($key);
					if ($value !== null) {
						$settings[$key] = $value;
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Get what features the method supports
	 */
	private function get_method_supports($method) {
		$supports = [];
		
		$supported_features = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
			'settings',
		];

		foreach ($supported_features as $feature) {
			if (method_exists($method, 'supports') && $method->supports($feature)) {
				$supports[] = $feature;
			}
		}

		return $supports;
	}

	/**
	 * Count instances of a shipping method
	 */
	private function count_method_instances($method_id) {
		$count = 0;
		$zones = WC_Shipping_Zones::get_zones();
		
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world) {
			$zones[] = $rest_of_world;
		}

		foreach ($zones as $zone_key => $zone_data) {
			$zone = null;
			
			// Handle zone data - could be array or object
			if (is_object($zone_data)) {
				// Already a zone object
				$zone = $zone_data;
			} elseif (is_array($zone_data) && isset($zone_data['id'])) {
				// Array with zone data, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_data['id']);
			} elseif (is_numeric($zone_key)) {
				// Zone ID is the key, get zone object
				$zone = WC_Shipping_Zones::get_zone($zone_key);
			}
			
			if (!$zone || !is_object($zone)) {
				continue;
			}

			if (method_exists($zone, 'get_shipping_methods')) {
				$zone_methods = $zone->get_shipping_methods(true);
				if (is_array($zone_methods)) {
					foreach ($zone_methods as $method) {
						if (is_object($method) && isset($method->id) && $method->id === $method_id) {
							$count++;
						}
					}
				}
			}
		}

		return $count;
	}
}


