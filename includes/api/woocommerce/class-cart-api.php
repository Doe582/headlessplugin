<?php

class RESTBridge_Cart_API {
	private $cart_restored_from_meta = false;
	private $suspend_snapshot_sync = false;

	public function __construct() {
		add_action('woocommerce_cart_updated', [$this, 'handle_cart_updated']);
		add_action('woocommerce_cart_item_removed', [$this, 'handle_cart_updated']);
		add_action('woocommerce_cart_item_restored', [$this, 'handle_cart_updated']);
		add_action('woocommerce_cart_item_set_quantity', [$this, 'handle_cart_updated']);
		add_action('woocommerce_cart_emptied', [$this, 'handle_cart_emptied']);
	}

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/cart', [
			'methods' => 'GET',
			'callback' => [$this, 'get_cart'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/cart/add', [
			'methods' => 'POST',
			'callback' => [$this, 'add_to_cart'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/cart/update', [
			'methods' => 'PUT',
			'callback' => [$this, 'update_cart'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/cart/remove', [
			'methods' => 'POST',
			'callback' => [$this, 'remove_from_cart'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/cart/clear', [
			'methods' => 'POST',
			'callback' => [$this, 'clear_cart'],
			'permission_callback' => '__return_true',
		]);
	}

	private function ensure_cart() {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}
		if (!WC()->cart) {
			if (function_exists('wc_load_cart')) {
				wc_load_cart();
			} else {
				WC()->cart = new WC_Cart();
			}
		}

		return true;
	}

	public function get_cart(WP_REST_Request $request = null) {
		$auth = $this->authenticate_request_user($request);
		if (is_wp_error($auth)) {
			return $auth;
		}

		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;
		$this->maybe_restore_user_cart();

		$cart = WC()->cart;
		$user_id = get_current_user_id();

		$items = [];

		foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
			$product = $cart_item['data'];
			$variation_id = isset($cart_item['variation_id']) && $cart_item['variation_id'] > 0 ? $cart_item['variation_id'] : null;
			
			$items[] = [
				'key' => $cart_item_key,
				'product_id' => $cart_item['product_id'],
				'variation_id' => $variation_id,
				'name' => $product->get_name(),
				'quantity' => $cart_item['quantity'],
				'price' => $product->get_price(),
				'line_total' => $cart_item['line_total'],
				'line_subtotal' => $cart_item['line_subtotal'],
				'sku' => $product->get_sku(),
				'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
			];
		}

		return rest_ensure_response([
			'items' => $items,
			'cart_total' => $cart->get_total(),
			'cart_subtotal' => $cart->get_subtotal(),
			'cart_tax' => $cart->get_total_tax(),
			'cart_count' => $cart->get_cart_contents_count(),
			'cart_needs_payment' => $cart->needs_payment(),
			'cart_needs_shipping' => $cart->needs_shipping(),
			'user_id' => $user_id ?: null,
			'is_user_cart' => $user_id > 0,
		]);
	}

	public function add_to_cart(WP_REST_Request $request) {
		$auth = $this->authenticate_request_user($request);
		if (is_wp_error($auth)) {
			return $auth;
		}

		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;
		
		// Restore cart from snapshot if needed (only if cart is empty)
		$this->maybe_restore_user_cart();

		$params = $request->get_json_params();
		$product_id = isset($params['product_id']) ? (int) $params['product_id'] : (int) $request->get_param('product_id');
		$quantity = isset($params['quantity']) ? (int) $params['quantity'] : ((int) $request->get_param('quantity') ?: 1);
		$variation_id = isset($params['variation_id']) ? (int) $params['variation_id'] : 0;
		$variation = isset($params['variation']) && is_array($params['variation']) ? $params['variation'] : [];

		if (!$product_id) {
			return new WP_Error('missing_product_id', 'Product ID is required', ['status' => 400]);
		}

		if ($quantity <= 0) {
			return new WP_Error('invalid_quantity', 'Quantity must be greater than 0', ['status' => 400]);
		}

		// Sanitize variation attributes
		$sanitized_variation = [];
		foreach ($variation as $key => $value) {
			$sanitized_variation[sanitize_key($key)] = sanitize_text_field($value);
		}

		// Clear any previous error notices
		if (function_exists('wc_clear_notices')) {
			wc_clear_notices();
		}

		// Add product to cart
		if ($variation_id) {
			$added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $sanitized_variation);
		} else {
			$added = WC()->cart->add_to_cart($product_id, $quantity);
		}

		if (!$added) {
			$error_message = $this->get_cart_error_message();
			return new WP_Error(
				'add_failed',
				$error_message ?: 'Failed to add product to cart',
				['status' => 400]
			);
		}

		// Recalculate cart totals to ensure everything is up to date
		WC()->cart->calculate_totals();

		// Persist the updated cart snapshot
		$this->persist_user_cart_snapshot();

		return rest_ensure_response([
			'success' => true,
			'message' => 'Product added to cart',
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function update_cart(WP_REST_Request $request) {
		$auth = $this->authenticate_request_user($request);
		if (is_wp_error($auth)) {
			return $auth;
		}
		
		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;
		$this->maybe_restore_user_cart();

		$params = $request->get_json_params();
		$cart_item_key = isset($params['key']) ? sanitize_text_field($params['key']) : '';
		$product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;
		$quantity = isset($params['quantity']) ? (int) $params['quantity'] : 0;

		if ($quantity < 0) {
			return new WP_Error('invalid_quantity', 'Quantity must be 0 or greater', ['status' => 400]);
		}

		// Find cart item by product_id if key not provided
		if (!$cart_item_key && $product_id) {
			foreach (WC()->cart->get_cart() as $item_key => $cart_item) {
				if ($cart_item['product_id'] == $product_id) {
					$cart_item_key = $item_key;
					break;
				}
			}
		}

		if (!$cart_item_key) {
			return new WP_Error('missing_key', 'Either cart item key or product_id is required', ['status' => 400]);
		}

		if (!isset(WC()->cart->cart_contents[$cart_item_key])) {
			return new WP_Error('item_not_found', 'Cart item not found', ['status' => 404]);
		}

		// If quantity is 0, remove the item
		if ($quantity === 0) {
			WC()->cart->remove_cart_item($cart_item_key);
		} else {
			$updated = WC()->cart->set_quantity($cart_item_key, $quantity);
			
			if (!$updated) {
				return new WP_Error('update_failed', 'Failed to update cart item', ['status' => 400]);
			}
		}

		// Recalculate cart totals
		WC()->cart->calculate_totals();
		$this->persist_user_cart_snapshot();

		return rest_ensure_response([
			'success' => true,
			'message' => $quantity === 0 ? 'Item removed from cart' : 'Cart updated successfully',
			'quantity' => $quantity,
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function remove_from_cart(WP_REST_Request $request) {
		$auth = $this->authenticate_request_user($request);
		if (is_wp_error($auth)) {
			return $auth;
		}

		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;

		$params = $request->get_json_params();
		$cart_item_key = isset($params['key']) ? sanitize_text_field($params['key']) : '';
		$product_id = isset($params['product_id']) ? (int) $params['product_id'] : 0;

		if ($cart_item_key) {
			WC()->cart->remove_cart_item($cart_item_key);
		} elseif ($product_id) {
			foreach (WC()->cart->get_cart() as $item_key => $cart_item) {
				if ($cart_item['product_id'] == $product_id) {
					WC()->cart->remove_cart_item($item_key);
					break;
				}
			}
		} else {
			return new WP_Error('missing_parameter', 'Either key or product_id is required', ['status' => 400]);
		}

		$this->persist_user_cart_snapshot();

		return rest_ensure_response([
			'success' => true,
			'message' => 'Item removed from cart',
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function clear_cart(WP_REST_Request $request = null) {
		$auth = $this->authenticate_request_user($request);
		if (is_wp_error($auth)) {
			return $auth;
		}

		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;
		$this->maybe_restore_user_cart();

		WC()->cart->empty_cart();
		$this->clear_user_cart_snapshot();

		return rest_ensure_response([
			'success' => true,
			'message' => 'Cart cleared',
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	private function authenticate_request_user(WP_REST_Request $request = null) {
		if (is_user_logged_in()) {
			return true;
		}

		if (!$request instanceof WP_REST_Request) {
			return new WP_Error('missing_request', 'REST request context required', ['status' => 400]);
		}

		$token = $this->extract_bearer_token($request);

		if (empty($token)) {
			return new WP_Error('missing_bearer_token', 'Bearer token is required', ['status' => 401]);
		}

		$user = $this->get_user_by_api_token($token);

		if (!$user) {
			return new WP_Error('invalid_bearer_token', 'Invalid bearer token', ['status' => 401]);
		}

		wp_set_current_user($user->ID);

		if (function_exists('wc_load_cart')) {
			wc_load_cart();
		}

		return true;
	}

	private function extract_bearer_token(WP_REST_Request $request) {
		$authorization = $request->get_header('authorization');
		if (!empty($authorization)) {
			if (stripos($authorization, 'bearer ') === 0) {
				return trim(substr($authorization, 7));
			}

			if (stripos($authorization, 'basic ') === 0) {
				// Allow backwards compatibility for Basic auth tokens.
				$decoded = base64_decode(substr($authorization, 6));
				if ($decoded !== false && strpos($decoded, ':') !== false) {
					list(, $token) = explode(':', $decoded, 2);
					return $token;
				}
			}
		}

		$header_token = $request->get_header('x-bearer-token');
		if (!empty($header_token)) {
			return $header_token;
		}

		$param_token = $request->get_param('bearer_token');
		if (!empty($param_token)) {
			return sanitize_text_field($param_token);
		}

		// Backward compatibility for legacy param names.
		$legacy = $request->get_param('user_token');
		return !empty($legacy) ? sanitize_text_field($legacy) : '';
	}

	private function get_user_by_api_token($token) {
		$users = get_users([
			'meta_key' => '_api_token',
			'meta_value' => $token,
			'number' => 1,
			'count_total' => false,
		]);

		return !empty($users) ? $users[0] : null;
	}

	private function maybe_restore_user_cart() {
		if ($this->cart_restored_from_meta) {
			return;
		}

		if (!is_user_logged_in()) {
			return;
		}

		$user_id = get_current_user_id();
		$snapshot = get_user_meta($user_id, '_api_cart_snapshot', true);

		$this->cart_restored_from_meta = true;

		if (empty($snapshot) || !is_array($snapshot)) {
			return;
		}

		// Only restore if cart is empty (to avoid conflicts)
		$current_cart_count = WC()->cart->get_cart_contents_count();
		if ($current_cart_count > 0) {
			// Cart already has items, don't restore (might be from session or previous operation)
			return;
		}

		$this->suspend_snapshot_sync = true;
		WC()->cart->empty_cart();

		foreach ($snapshot as $item) {
			$product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
			$quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
			$variation_id = isset($item['variation_id']) ? (int) $item['variation_id'] : 0;
			$variation = isset($item['variation']) && is_array($item['variation']) ? $item['variation'] : [];
			$cart_item_data = isset($item['cart_item_data']) && is_array($item['cart_item_data']) ? $item['cart_item_data'] : [];

			if ($product_id > 0 && $quantity > 0) {
				$result = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
				// If add fails, continue with next item (don't break the restoration)
				if (!$result) {
					continue;
				}
			}
		}

		WC()->cart->calculate_totals();
		$this->suspend_snapshot_sync = false;
		// Don't persist here - let the calling method handle it after the operation
	}

	private function persist_user_cart_snapshot() {
		if (!is_user_logged_in()) {
			return;
		}

		$snapshot = [];

		foreach (WC()->cart->get_cart() as $cart_item) {
			$snapshot[] = [
				'product_id' => $cart_item['product_id'],
				'quantity' => $cart_item['quantity'],
				'variation_id' => isset($cart_item['variation_id']) ? (int) $cart_item['variation_id'] : 0,
				'variation' => isset($cart_item['variation']) ? $cart_item['variation'] : [],
				'cart_item_data' => isset($cart_item['cart_item_data']) ? $cart_item['cart_item_data'] : [],
			];
		}

		update_user_meta(get_current_user_id(), '_api_cart_snapshot', $snapshot);
	}

	private function clear_user_cart_snapshot() {
		if (!is_user_logged_in()) {
			return;
		}

		delete_user_meta(get_current_user_id(), '_api_cart_snapshot');
	}

	public function handle_cart_updated() {
		if ($this->suspend_snapshot_sync || !is_user_logged_in()) {
			return;
		}

		$this->persist_user_cart_snapshot();
	}

	public function handle_cart_emptied() {
		if ($this->suspend_snapshot_sync || !is_user_logged_in()) {
			return;
		}

		$this->clear_user_cart_snapshot();
	}

	private function get_cart_error_message() {
		if (!function_exists('wc_get_notices')) {
			return '';
		}

		$errors = wc_get_notices('error');

		if (empty($errors)) {
			return '';
		}

		$first = reset($errors);
		wc_clear_notices();

		if (is_array($first) && isset($first['notice'])) {
			return wp_strip_all_tags($first['notice']);
		}

		if (is_string($first)) {
			return wp_strip_all_tags($first);
		}

		return '';
	}
}

