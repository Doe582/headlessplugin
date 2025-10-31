<?php

class RESTBridge_Reports_API {

	public function register_routes() {
		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/sales', [
			'methods' => 'GET',
			'callback' => [$this, 'get_sales_report'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/top-sellers', [
			'methods' => 'GET',
			'callback' => [$this, 'get_top_sellers'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/orders/totals', [
			'methods' => 'GET',
			'callback' => [$this, 'get_orders_totals'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/products/totals', [
			'methods' => 'GET',
			'callback' => [$this, 'get_products_totals'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/customers/totals', [
			'methods' => 'GET',
			'callback' => [$this, 'get_customers_totals'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/coupons/totals', [
			'methods' => 'GET',
			'callback' => [$this, 'get_coupons_totals'],
			'permission_callback' => [$this, 'check_permission'],
		]);

		register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reports/reviews/totals', [
			'methods' => 'GET',
			'callback' => [$this, 'get_reviews_totals'],
			'permission_callback' => [$this, 'check_permission'],
		]);
	}

	public function get_sales_report(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		
		$start_date = isset($params['start_date']) ? sanitize_text_field($params['start_date']) : date('Y-m-d', strtotime('-30 days'));
		$end_date = isset($params['end_date']) ? sanitize_text_field($params['end_date']) : date('Y-m-d');
		
		$start_timestamp = strtotime($start_date);
		$end_timestamp = strtotime($end_date . ' 23:59:59');

		$args = [
			'limit' => -1,
			'status' => ['completed', 'processing', 'on-hold'],
			'date_created' => $start_timestamp . '...' . $end_timestamp,
		];

		$orders = wc_get_orders($args);

		$total_sales = 0;
		$total_orders = count($orders);
		$total_items = 0;
		$total_tax = 0;
		$total_shipping = 0;
		$total_refunds = 0;

		$daily_sales = [];
		$status_breakdown = [];

		foreach ($orders as $order) {
			$total_sales += $order->get_total();
			$total_tax += $order->get_total_tax();
			$total_shipping += $order->get_shipping_total();
			$total_items += $order->get_item_count();

			$order_date = $order->get_date_created()->date('Y-m-d');
			if (!isset($daily_sales[$order_date])) {
				$daily_sales[$order_date] = [
					'date' => $order_date,
					'orders' => 0,
					'sales' => 0,
				];
			}
			$daily_sales[$order_date]['orders']++;
			$daily_sales[$order_date]['sales'] += $order->get_total();

			$status = $order->get_status();
			if (!isset($status_breakdown[$status])) {
				$status_breakdown[$status] = [
					'status' => $status,
					'count' => 0,
					'total' => 0,
				];
			}
			$status_breakdown[$status]['count']++;
			$status_breakdown[$status]['total'] += $order->get_total();

			// Calculate refunds
			$refunds = $order->get_refunds();
			foreach ($refunds as $refund) {
				$total_refunds += abs($refund->get_amount());
			}
		}

		$daily_sales = array_values($daily_sales);
		$status_breakdown = array_values($status_breakdown);

		return rest_ensure_response([
			'period' => [
				'start_date' => $start_date,
				'end_date' => $end_date,
			],
			'totals' => [
				'total_sales' => $total_sales,
				'total_orders' => $total_orders,
				'total_items' => $total_items,
				'total_tax' => $total_tax,
				'total_shipping' => $total_shipping,
				'total_refunds' => $total_refunds,
				'net_sales' => $total_sales - $total_refunds,
				'average_order_value' => $total_orders > 0 ? $total_sales / $total_orders : 0,
			],
			'daily_sales' => $daily_sales,
			'status_breakdown' => $status_breakdown,
		]);
	}

	public function get_top_sellers(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		$limit = isset($params['limit']) ? (int) $params['limit'] : 10;
		
		$start_date = isset($params['start_date']) ? sanitize_text_field($params['start_date']) : date('Y-m-d', strtotime('-30 days'));
		$end_date = isset($params['end_date']) ? sanitize_text_field($params['end_date']) : date('Y-m-d');
		
		$start_timestamp = strtotime($start_date);
		$end_timestamp = strtotime($end_date . ' 23:59:59');

		$args = [
			'limit' => -1,
			'status' => ['completed', 'processing'],
			'date_created' => $start_timestamp . '...' . $end_timestamp,
		];

		$orders = wc_get_orders($args);
		$product_sales = [];

		foreach ($orders as $order) {
			foreach ($order->get_items() as $item) {
				$product_id = $item->get_product_id();
				$variation_id = $item->get_variation_id();
				$id = $variation_id ? $variation_id : $product_id;

				if (!isset($product_sales[$id])) {
					$product = wc_get_product($product_id);
					$product_sales[$id] = [
						'product_id' => $product_id,
						'variation_id' => $variation_id,
						'name' => $item->get_name(),
						'quantity' => 0,
						'total' => 0,
						'sku' => $product ? $product->get_sku() : '',
					];
				}

				$product_sales[$id]['quantity'] += $item->get_quantity();
				$product_sales[$id]['total'] += $item->get_total();
			}
		}

		// Sort by quantity
		usort($product_sales, function($a, $b) {
			return $b['quantity'] - $a['quantity'];
		});

		$top_sellers = array_slice($product_sales, 0, $limit);

		return rest_ensure_response([
			'period' => [
				'start_date' => $start_date,
				'end_date' => $end_date,
			],
			'limit' => $limit,
			'top_sellers' => $top_sellers,
		]);
	}

	public function get_orders_totals(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$order_statuses = wc_get_order_statuses();
		$totals = [];

		foreach ($order_statuses as $status => $label) {
			$status = str_replace('wc-', '', $status);
			$count = wc_orders_count($status);
			
			$orders = wc_get_orders([
				'status' => $status,
				'limit' => -1,
			]);

			$total_amount = 0;
			foreach ($orders as $order) {
				$total_amount += $order->get_total();
			}

			$totals[] = [
				'status' => $status,
				'label' => $label,
				'count' => $count,
				'total_amount' => $total_amount,
			];
		}

		// Overall totals
		$all_orders = wc_get_orders(['limit' => -1]);
		$grand_total = 0;
		$grand_count = count($all_orders);

		foreach ($all_orders as $order) {
			if (in_array($order->get_status(), ['completed', 'processing', 'on-hold'])) {
				$grand_total += $order->get_total();
			}
		}

		return rest_ensure_response([
			'by_status' => $totals,
			'grand_total' => [
				'total_orders' => $grand_count,
				'total_revenue' => $grand_total,
				'average_order_value' => $grand_count > 0 ? $grand_total / $grand_count : 0,
			],
		]);
	}

	public function get_products_totals(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$args = [
			'post_type' => 'product',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		];

		$query = new WP_Query($args);
		$total_products = $query->found_posts;

		$by_type = [];
		$by_status = [];
		$low_stock = 0;
		$out_of_stock = 0;
		$in_stock = 0;

		while ($query->have_posts()) {
			$query->the_post();
			$product = wc_get_product(get_the_ID());

			if (!$product) {
				continue;
			}

			$type = $product->get_type();
			if (!isset($by_type[$type])) {
				$by_type[$type] = 0;
			}
			$by_type[$type]++;

			$stock_status = $product->get_stock_status();
			if (!isset($by_status[$stock_status])) {
				$by_status[$stock_status] = 0;
			}
			$by_status[$stock_status]++;

			if ($stock_status === 'instock') {
				$in_stock++;
			} elseif ($stock_status === 'outofstock') {
				$out_of_stock++;
			} elseif ($stock_status === 'onbackorder') {
				$low_stock++;
			}

			if ($product->managing_stock() && $product->get_stock_quantity() < 5 && $product->get_stock_quantity() > 0) {
				$low_stock++;
			}
		}
		wp_reset_postdata();

		return rest_ensure_response([
			'total_products' => $total_products,
			'by_type' => $by_type,
			'by_stock_status' => $by_status,
			'stock_summary' => [
				'in_stock' => $in_stock,
				'out_of_stock' => $out_of_stock,
				'low_stock' => $low_stock,
			],
		]);
	}

	public function get_customers_totals(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$user_count = count_users();
		$total_customers = $user_count['avail_roles']['customer'] ?? 0;
		$total_users = $user_count['total_users'];

		// Get customers with orders
		$customers_with_orders = [];
		$orders = wc_get_orders(['limit' => -1]);

		foreach ($orders as $order) {
			$customer_id = $order->get_customer_id();
			if ($customer_id && !isset($customers_with_orders[$customer_id])) {
				$customers_with_orders[$customer_id] = true;
			}
		}

		$total_customers_with_orders = count($customers_with_orders);

		// Calculate total revenue from customers
		$total_revenue = 0;
		foreach ($orders as $order) {
			if (in_array($order->get_status(), ['completed', 'processing'])) {
				$total_revenue += $order->get_total();
			}
		}

		return rest_ensure_response([
			'total_customers' => $total_customers,
			'total_users' => $total_users,
			'customers_with_orders' => $total_customers_with_orders,
			'customers_without_orders' => $total_customers - $total_customers_with_orders,
			'total_revenue' => $total_revenue,
			'average_customer_value' => $total_customers_with_orders > 0 ? $total_revenue / $total_customers_with_orders : 0,
		]);
	}

	public function get_coupons_totals(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$args = [
			'post_type' => 'shop_coupon',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		];

		$query = new WP_Query($args);
		$total_coupons = $query->found_posts;

		$by_type = [];
		$total_usage = 0;
		$total_discount = 0;

		while ($query->have_posts()) {
			$query->the_post();
			$coupon = new WC_Coupon(get_the_ID());

			$discount_type = $coupon->get_discount_type();
			if (!isset($by_type[$discount_type])) {
				$by_type[$discount_type] = 0;
			}
			$by_type[$discount_type]++;

			$total_usage += $coupon->get_usage_count();
		}
		wp_reset_postdata();

		// Calculate total discounts from orders
		$orders = wc_get_orders(['limit' => -1]);
		foreach ($orders as $order) {
			$total_discount += $order->get_discount_total();
		}

		return rest_ensure_response([
			'total_coupons' => $total_coupons,
			'by_discount_type' => $by_type,
			'total_usage' => $total_usage,
			'total_discount_given' => $total_discount,
			'average_discount_per_coupon' => $total_coupons > 0 ? $total_discount / $total_coupons : 0,
		]);
	}

	public function get_reviews_totals(WP_REST_Request $request) {
		if (!function_exists('WC')) {
			return new WP_Error('woocommerce_not_active', 'WooCommerce not active', ['status' => 500]);
		}

		$params = $request->get_query_params();
		$post_type = isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'product';

		$args = [
			'status' => 'approve',
			'post_type' => $post_type,
		];

		$comments = get_comments($args);
		$total_reviews = count($comments);

		$by_rating = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
		$total_rating = 0;

		foreach ($comments as $comment) {
			$rating = get_comment_meta($comment->comment_ID, 'rating', true);
			if ($rating) {
				$rating = (int) $rating;
				if (isset($by_rating[$rating])) {
					$by_rating[$rating]++;
				}
				$total_rating += $rating;
			}
		}

		$average_rating = $total_reviews > 0 ? $total_rating / $total_reviews : 0;

		// Get pending reviews
		$pending_args = $args;
		$pending_args['status'] = 'hold';
		$pending_reviews = count(get_comments($pending_args));

		return rest_ensure_response([
			'total_reviews' => $total_reviews,
			'pending_reviews' => $pending_reviews,
			'approved_reviews' => $total_reviews,
			'by_rating' => $by_rating,
			'average_rating' => round($average_rating, 2),
			'total_rating_points' => $total_rating,
			'post_type' => $post_type,
		]);
	}

	public function check_permission() {
		return current_user_can('manage_woocommerce');
	}
}

