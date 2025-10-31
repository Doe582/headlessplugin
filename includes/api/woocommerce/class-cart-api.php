<?php

class RESTBridge_Cart_API {

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

	public function get_cart() {
		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;

		$cart = WC()->cart;
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
		]);
	}

	public function add_to_cart(WP_REST_Request $request) {
		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;

		$params = $request->get_json_params();
		$product_id = isset($params['product_id']) ? (int) $params['product_id'] : (int) $request->get_param('product_id');
		$quantity = isset($params['quantity']) ? (int) $params['quantity'] : ((int) $request->get_param('quantity') ?: 1);
		$variation_id = isset($params['variation_id']) ? (int) $params['variation_id'] : 0;
		$variation = isset($params['variation']) && is_array($params['variation']) ? $params['variation'] : [];

		if (!$product_id) {
			return new WP_Error('missing_product_id', 'Product ID is required', ['status' => 400]);
		}

		// Sanitize variation attributes
		$sanitized_variation = [];
		foreach ($variation as $key => $value) {
			$sanitized_variation[sanitize_key($key)] = sanitize_text_field($value);
		}

		if ($variation_id) {
			$added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $sanitized_variation);
		} else {
			$added = WC()->cart->add_to_cart($product_id, $quantity);
		}

		if (!$added) {
			return new WP_Error('add_failed', 'Failed to add product to cart', ['status' => 400]);
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Product added to cart',
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function update_cart(WP_REST_Request $request) {
		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;

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

		return rest_ensure_response([
			'success' => true,
			'message' => $quantity === 0 ? 'Item removed from cart' : 'Cart updated successfully',
			'quantity' => $quantity,
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function remove_from_cart(WP_REST_Request $request) {
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

		return rest_ensure_response([
			'success' => true,
			'message' => 'Item removed from cart',
			'cart' => $this->get_cart()->get_data(),
		]);
	}

	public function clear_cart() {
		$ok = $this->ensure_cart();
		if (is_wp_error($ok)) return $ok;

		WC()->cart->empty_cart();

		return rest_ensure_response([
			'success' => true,
			'message' => 'Cart cleared',
			'cart' => $this->get_cart()->get_data(),
		]);
	}
}

