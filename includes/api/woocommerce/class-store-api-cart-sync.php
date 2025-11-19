<?php
/**
 * WooCommerce Store API Cart Sync Helper
 * 
 * Ensures cart added via Store API is visible on the website frontend
 */
class RESTBridge_Store_API_Cart_Sync {
	
	private $is_store_api_request = false;
	private $syncing = false; // Prevent infinite loops
	
	/**
	 * Initialize hooks
	 */
	public function __construct() {
		// Check if this is a Store API request
		add_filter('rest_pre_dispatch', [$this, 'check_store_api_request'], 10, 3);
		
		// Save session before REST response is served (ensures headers still available)
		add_filter('rest_pre_serve_request', [$this, 'save_session_if_store_api'], 999, 3);
		
		// For logged-in users, sync to persistent cart (only once per cart update)
		add_action('woocommerce_cart_updated', [$this, 'sync_persistent_cart_once'], 999);
	}
	
	/**
	 * Check if this is a Store API request
	 */
	public function check_store_api_request($result, $server, $request) {
		if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/wc/store/v1/') !== false) {
			// Mark that we need to save session
			$this->is_store_api_request = true;
		}
		return $result;
	}
	
	
	/**
	 * Save session on shutdown if this was a Store API request
	 * Only called once per request, prevents infinite loops
	 * This ensures cart is saved and visible on frontend
	 */
	public function save_session_if_store_api($served, $result, $request) {
		// Only process Store API requests
		if (!$this->is_store_api_request || $this->syncing) {
			return $served;
		}
		
		$this->syncing = true; // Prevent recursive calls
		
		if (!function_exists('WC')) {
			$this->syncing = false;
			return;
		}
		
		$wc = WC();
		
		// Ensure session is initialized
		if (empty($wc->session)) {
			if (method_exists($wc, 'initialize_session')) {
				$wc->initialize_session();
			}
		}
		
		// Ensure cart is loaded
		if (empty($wc->cart)) {
			wc_load_cart();
		}
		
		// Force cart to recalculate and save
		if ($wc->cart) {
			// Temporarily remove our hook to prevent loop
			remove_action('woocommerce_cart_updated', [$this, 'sync_persistent_cart_once'], 999);
			
			// Recalculate totals to ensure cart is in sync
			if (method_exists($wc->cart, 'calculate_totals')) {
				$wc->cart->calculate_totals();
			}
			
			// Save cart to session - this is critical for frontend visibility
			if (method_exists($wc->cart, 'set_session')) {
				$wc->cart->set_session();
			}
			
			// Restore hook
			add_action('woocommerce_cart_updated', [$this, 'sync_persistent_cart_once'], 999);
		}
		
		// Force session to save to database
		if ($wc->session) {
			// Save session data to database
			if (method_exists($wc->session, 'save_data')) {
				$wc->session->save_data();
			} elseif (method_exists($wc->session, 'save')) {
				$wc->session->save();
			}
			
			// Also ensure cookie is set properly
			if (method_exists($wc->session, 'set_customer_session_cookie')) {
				$wc->session->set_customer_session_cookie(true);
			}
		}
		
		// For logged-in users, also sync to persistent cart
		if (is_user_logged_in()) {
			$this->sync_persistent_cart_once();
		}
		
		$this->syncing = false;
		return $served;
	}
	
	/**
	 * Sync cart to persistent storage for logged-in users
	 * Only called once per cart update to prevent loops
	 */
	public function sync_persistent_cart_once() {
		// Prevent recursive calls
		if ($this->syncing) {
			return;
		}
		
		if (!is_user_logged_in() || !function_exists('WC')) {
			return;
		}
		
		$this->syncing = true;
		
		$user_id = get_current_user_id();
		$wc = WC();
		
		if (!$wc->cart) {
			$this->syncing = false;
			return;
		}
		
		// Get current cart contents
		$cart_contents = $wc->cart->get_cart();
		
		// Build persistent cart array (WooCommerce format)
		$persistent_cart = [
			'cart' => $cart_contents,
		];
		
		// Save to user meta
		update_user_meta($user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), $persistent_cart);
		
		$this->syncing = false;
	}
}

