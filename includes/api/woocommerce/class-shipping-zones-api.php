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

		$zones = WC_Shipping_Zones::get_zones();
		$formatted_zones = [];

		foreach ($zones as $zone) {
			$formatted_zones[] = $this->format_zone($zone);
		}

		// Add "Rest of the World" zone (zone ID 0)
		$rest_of_world = WC_Shipping_Zones::get_zone(0);
		if ($rest_of_world) {
			$formatted_zones[] = $this->format_zone($rest_of_world);
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

		// Update method settings if provided
		if (isset($params['settings']) && is_array($params['settings'])) {
			$method = $zone->get_shipping_method($instance_id);
			if ($method) {
				foreach ($params['settings'] as $key => $value) {
					$method->set_instance_option($key, sanitize_text_field($value));
				}
			}
		}

		$zone->save();

		return rest_ensure_response([
			'success' => true,
			'instance_id' => $instance_id,
			'method' => $this->format_method($zone->get_shipping_method($instance_id), $instance_id),
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

		$method = $zone->get_shipping_method($instance_id);

		if (!$method) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

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

		$method = $zone->get_shipping_method($instance_id);

		if (!$method) {
			return new WP_Error('method_not_found', 'Shipping method not found', ['status' => 404]);
		}

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

		$method = $zone->get_shipping_method($instance_id);

		if (!$method) {
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
		$locations = [];
		foreach ($zone->get_locations() as $location) {
			$locations[] = [
				'code' => $location->code,
				'type' => $location->type,
			];
		}

		$methods = [];
		foreach ($zone->get_shipping_methods(true) as $instance_id => $method) {
			$methods[] = $this->format_method($method, $instance_id);
		}

		return [
			'id' => $zone->get_id(),
			'name' => $zone->get_zone_name(),
			'order' => $zone->get_zone_order(),
			'locations' => $locations,
			'methods' => $methods,
			'method_count' => count($methods),
		];
	}

	private function format_method($method, $instance_id) {
		$settings = [];
		if (method_exists($method, 'get_instance_option')) {
			$option_keys = array_keys($method->get_instance_option_keys());
			foreach ($option_keys as $key) {
				$settings[$key] = $method->get_instance_option($key);
			}
		}

		return [
			'instance_id' => $instance_id,
			'method_id' => $method->id,
			'method_title' => $method->get_method_title(),
			'title' => $method->get_title(),
			'enabled' => $method->is_enabled(),
			'settings' => $settings,
		];
	}

	public function check_permission() {
		return current_user_can('manage_woocommerce');
	}
}

