<?php

class RESTBridge_Orders_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders', [
			'methods' => 'GET',
			'callback' => [$this, 'get_orders'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders', [
			'methods' => 'POST',
			'callback' => [$this, 'create_order'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders/(?P<id>\d+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_order'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders/(?P<id>\d+)', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_order'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders/(?P<id>\d+)', [
			'methods' => 'DELETE',
			'callback' => [$this, 'delete_order'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/orders/(?P<id>\d+)/status', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_order_status'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_orders(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		
		$args = [
			'limit' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
			'paged' => isset($params['page']) ? (int) $params['page'] : 1,
			'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
			'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
		];

		if (isset($params['status'])) {
			$args['status'] = sanitize_text_field($params['status']);
		}

		if (isset($params['customer'])) {
			$args['customer_id'] = (int) $params['customer'];
		}

		if (isset($params['date_after'])) {
			$args['date_created'] = strtotime($params['date_after']);
		}

		if (isset($params['date_before'])) {
			$args['date_created'] = (isset($args['date_created']) ? $args['date_created'] . '...' : '') . strtotime($params['date_before']);
		}

		$orders = wc_get_orders($args);
		$formatted_orders = [];

		foreach ($orders as $order) {
			$formatted_orders[] = $this->format_order($order);
		}

		return rest_ensure_response([
			'orders' => $formatted_orders,
			'total' => count($formatted_orders),
		]);
	}

	public function get_order(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$order_id = (int) $request['id'];
		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
		}

		return rest_ensure_response($this->format_order($order, true));
	}

	public function create_order(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_json_params();

		$order = wc_create_order();

		if (is_wp_error($order)) {
			return $order;
		}

		// Set customer
		if (isset($params['customer_id'])) {
			$order->set_customer_id((int) $params['customer_id']);
		}

		// Add line items
		if (isset($params['line_items']) && is_array($params['line_items'])) {
			foreach ($params['line_items'] as $item) {
				$product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
				$quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
				$variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;

				if ($product_id) {
					$order->add_product(wc_get_product($variation_id ? $variation_id : $product_id), $quantity);
				}
			}
		}

		// Set billing address
		if (isset($params['billing'])) {
			$billing = $params['billing'];
			$order->set_billing_first_name(isset($billing['first_name']) ? sanitize_text_field($billing['first_name']) : '');
			$order->set_billing_last_name(isset($billing['last_name']) ? sanitize_text_field($billing['last_name']) : '');
			$order->set_billing_company(isset($billing['company']) ? sanitize_text_field($billing['company']) : '');
			$order->set_billing_address_1(isset($billing['address_1']) ? sanitize_text_field($billing['address_1']) : '');
			$order->set_billing_address_2(isset($billing['address_2']) ? sanitize_text_field($billing['address_2']) : '');
			$order->set_billing_city(isset($billing['city']) ? sanitize_text_field($billing['city']) : '');
			$order->set_billing_state(isset($billing['state']) ? sanitize_text_field($billing['state']) : '');
			$order->set_billing_postcode(isset($billing['postcode']) ? sanitize_text_field($billing['postcode']) : '');
			$order->set_billing_country(isset($billing['country']) ? sanitize_text_field($billing['country']) : '');
			$order->set_billing_email(isset($billing['email']) ? sanitize_email($billing['email']) : '');
			$order->set_billing_phone(isset($billing['phone']) ? sanitize_text_field($billing['phone']) : '');
		}

		// Set shipping address
		if (isset($params['shipping'])) {
			$shipping = $params['shipping'];
			$order->set_shipping_first_name(isset($shipping['first_name']) ? sanitize_text_field($shipping['first_name']) : '');
			$order->set_shipping_last_name(isset($shipping['last_name']) ? sanitize_text_field($shipping['last_name']) : '');
			$order->set_shipping_company(isset($shipping['company']) ? sanitize_text_field($shipping['company']) : '');
			$order->set_shipping_address_1(isset($shipping['address_1']) ? sanitize_text_field($shipping['address_1']) : '');
			$order->set_shipping_address_2(isset($shipping['address_2']) ? sanitize_text_field($shipping['address_2']) : '');
			$order->set_shipping_city(isset($shipping['city']) ? sanitize_text_field($shipping['city']) : '');
			$order->set_shipping_state(isset($shipping['state']) ? sanitize_text_field($shipping['state']) : '');
			$order->set_shipping_postcode(isset($shipping['postcode']) ? sanitize_text_field($shipping['postcode']) : '');
			$order->set_shipping_country(isset($shipping['country']) ? sanitize_text_field($shipping['country']) : '');
		}

		// Set payment method
		if (isset($params['payment_method'])) {
			$order->set_payment_method(sanitize_text_field($params['payment_method']));
		}

		if (isset($params['payment_method_title'])) {
			$order->set_payment_method_title(sanitize_text_field($params['payment_method_title']));
		}

		// Set shipping method
		if (isset($params['shipping_method'])) {
			$order->set_shipping_method(sanitize_text_field($params['shipping_method']));
		}

		if (isset($params['shipping_total'])) {
			$order->set_shipping_total((float) $params['shipping_total']);
		}

		// Set order status
		if (isset($params['status'])) {
			$order->set_status(sanitize_text_field($params['status']));
		}

		// Add coupon if provided
		if (isset($params['coupon_code'])) {
			$order->apply_coupon(sanitize_text_field($params['coupon_code']));
		}

		// Set customer note
		if (isset($params['customer_note'])) {
			$order->set_customer_note(sanitize_textarea_field($params['customer_note']));
		}

		$order->calculate_totals();
		$order->save();

		return rest_ensure_response($this->format_order($order, true), 201);
	}

	public function update_order(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$order_id = (int) $request['id'];
		$params = $request->get_json_params();

		$order = wc_get_order($order_id);
		
		if (!$order) {
			return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
		}

		// Update customer
		if (isset($params['customer_id'])) {
			$order->set_customer_id((int) $params['customer_id']);
		}

		// Update billing address
		if (isset($params['billing'])) {
			$billing = $params['billing'];
			if (isset($billing['first_name'])) $order->set_billing_first_name(sanitize_text_field($billing['first_name']));
			if (isset($billing['last_name'])) $order->set_billing_last_name(sanitize_text_field($billing['last_name']));
			if (isset($billing['company'])) $order->set_billing_company(sanitize_text_field($billing['company']));
			if (isset($billing['address_1'])) $order->set_billing_address_1(sanitize_text_field($billing['address_1']));
			if (isset($billing['address_2'])) $order->set_billing_address_2(sanitize_text_field($billing['address_2']));
			if (isset($billing['city'])) $order->set_billing_city(sanitize_text_field($billing['city']));
			if (isset($billing['state'])) $order->set_billing_state(sanitize_text_field($billing['state']));
			if (isset($billing['postcode'])) $order->set_billing_postcode(sanitize_text_field($billing['postcode']));
			if (isset($billing['country'])) $order->set_billing_country(sanitize_text_field($billing['country']));
			if (isset($billing['email'])) $order->set_billing_email(sanitize_email($billing['email']));
			if (isset($billing['phone'])) $order->set_billing_phone(sanitize_text_field($billing['phone']));
		}

		// Update shipping address
		if (isset($params['shipping'])) {
			$shipping = $params['shipping'];
			if (isset($shipping['first_name'])) $order->set_shipping_first_name(sanitize_text_field($shipping['first_name']));
			if (isset($shipping['last_name'])) $order->set_shipping_last_name(sanitize_text_field($shipping['last_name']));
			if (isset($shipping['company'])) $order->set_shipping_company(sanitize_text_field($shipping['company']));
			if (isset($shipping['address_1'])) $order->set_shipping_address_1(sanitize_text_field($shipping['address_1']));
			if (isset($shipping['address_2'])) $order->set_shipping_address_2(sanitize_text_field($shipping['address_2']));
			if (isset($shipping['city'])) $order->set_shipping_city(sanitize_text_field($shipping['city']));
			if (isset($shipping['state'])) $order->set_shipping_state(sanitize_text_field($shipping['state']));
			if (isset($shipping['postcode'])) $order->set_shipping_postcode(sanitize_text_field($shipping['postcode']));
			if (isset($shipping['country'])) $order->set_shipping_country(sanitize_text_field($shipping['country']));
		}

		// Update status
		if (isset($params['status'])) {
			$order->set_status(sanitize_text_field($params['status']));
		}

		// Update customer note
		if (isset($params['customer_note'])) {
			$order->set_customer_note(sanitize_textarea_field($params['customer_note']));
		}

		if (isset($params['shipping_total'])) {
			$order->set_shipping_total((float) $params['shipping_total']);
		}

		$order->calculate_totals();
		$order->save();

		return rest_ensure_response($this->format_order($order, true));
	}

	public function update_order_status(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$order_id = (int) $request['id'];
		$params = $request->get_json_params();

		$order = wc_get_order($order_id);
		
		if (!$order) {
			return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
		}

		if (!isset($params['status'])) {
			return new WP_Error('missing_status', 'Status is required', ['status' => 400]);
		}

		$status = sanitize_text_field($params['status']);
		$order->set_status($status);
		$order->save();

		return rest_ensure_response([
			'success' => true,
			'order_id' => $order_id,
			'status' => $status,
			'order' => $this->format_order($order, true),
		]);
	}

	public function delete_order(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$order_id = (int) $request['id'];
		$force = isset($request['force']) && $request['force'];

		$order = wc_get_order($order_id);
		if (!$order) {
			return new WP_Error('order_not_found', 'Order not found', ['status' => 404]);
		}

		$order->delete($force);

		return rest_ensure_response(['deleted' => true, 'id' => $order_id]);
	}

	private function format_order($order, $detailed = false) {
		$formatted = [
			'id' => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'status' => $order->get_status(),
			'currency' => $order->get_currency(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : null,
			'date_modified' => $order->get_date_modified() ? $order->get_date_modified()->date('Y-m-d H:i:s') : null,
			'customer_id' => $order->get_customer_id(),
			'total' => $order->get_total(),
			'subtotal' => $order->get_subtotal(),
			'total_tax' => $order->get_total_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'discount_total' => $order->get_discount_total(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_note' => $order->get_customer_note(),
		];

		if ($detailed) {
			// Line items
			$line_items = [];
			foreach ($order->get_items() as $item_id => $item) {
				$product = $item->get_product();
				$line_items[] = [
					'id' => $item_id,
					'product_id' => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'name' => $item->get_name(),
					'quantity' => $item->get_quantity(),
					'total' => $item->get_total(),
					'subtotal' => $item->get_subtotal(),
					'sku' => $product ? $product->get_sku() : '',
				];
			}

			$formatted['line_items'] = $line_items;

			// Billing address
			$formatted['billing'] = [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'company' => $order->get_billing_company(),
				'address_1' => $order->get_billing_address_1(),
				'address_2' => $order->get_billing_address_2(),
				'city' => $order->get_billing_city(),
				'state' => $order->get_billing_state(),
				'postcode' => $order->get_billing_postcode(),
				'country' => $order->get_billing_country(),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			];

			// Shipping address
			$formatted['shipping'] = [
				'first_name' => $order->get_shipping_first_name(),
				'last_name' => $order->get_shipping_last_name(),
				'company' => $order->get_shipping_company(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'state' => $order->get_shipping_state(),
				'postcode' => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
			];

			// Coupons used
			$coupons = [];
			foreach ($order->get_coupon_codes() as $coupon_code) {
				$coupons[] = $coupon_code;
			}
			$formatted['coupons'] = $coupons;

			// Shipping method
			$shipping_methods = [];
			foreach ($order->get_shipping_methods() as $shipping_method) {
				$shipping_methods[] = [
					'method_id' => $shipping_method->get_method_id(),
					'method_title' => $shipping_method->get_method_title(),
					'total' => $shipping_method->get_total(),
				];
			}
			$formatted['shipping_methods'] = $shipping_methods;
		}

		return $formatted;
	}

	public function check_permission() {
		return current_user_can('manage_woocommerce');
	}
}

