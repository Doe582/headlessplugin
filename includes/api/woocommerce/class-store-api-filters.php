<?php

/**
 * Extends WooCommerce Store API (wc/store/v1/products) with custom filters by
 * normalizing incoming query params so the native Store API query can use them.
 */
class RESTBridge_Store_API_Filters {

    const STORE_PRODUCTS_ROUTE = '/wc/store/v1/products';
    
    /**
     * Static flag to track if invalid filters were detected
     * @var bool
     */
    private static $has_invalid_filter = false;

    public function __construct() {
        // Use rest_pre_dispatch to get access to request object
        add_filter('rest_pre_dispatch', [$this, 'inject_filters_into_store_request'], 10, 3);
        // Also hook into query args preparation with high priority to run last
        add_filter('woocommerce_rest_product_object_query', [$this, 'modify_query_args'], 999, 2);
        // Hook into posts_clauses as a final check - this runs during actual query execution
        add_filter('posts_clauses', [$this, 'modify_query_clauses'], 999, 2);
        // Final fallback: modify the response directly if invalid filters were detected
        add_filter('rest_prepare_product_object', [$this, 'check_response_for_invalid_filters'], 999, 3);
        // Add sort options to response and handle sorting
        add_filter('rest_post_dispatch', [$this, 'handle_sorting_and_response'], 10, 3);
    }

    /**
     * Normalize supported params before WooCommerce processes the request.
     */
    public function inject_filters_into_store_request($response, $server, $request) {
        if (!$this->should_handle_request($request)) {
            return $response;
        }

        // Set global request for use in other hooks
        global $wp_rest_request;
        $wp_rest_request = $request;

        // Reset flag for each request
        self::$has_invalid_filter = false;

        $this->normalize_stock_status($request);
        $this->normalize_category_param($request);
        $this->normalize_price_filters($request);
        $this->map_taxonomy_filters($request);
        $this->normalize_sorting_param($request);

        return $response;
    }

    /**
     * Modify query args directly as a backup method
     * This ensures strict filtering works even if rest_pre_dispatch doesn't catch it
     * Runs with high priority (999) to ensure it executes last and can't be overridden
     */
    public function modify_query_args($args, $request) {
        if (!$this->should_handle_request($request)) {
            return $args;
        }

        // Check if we already set empty result in the request
        $include = $request->get_param('include');
        if (is_array($include) && in_array(0, $include, true)) {
            // Force empty result by setting post__in to non-existent ID
            // Clear any existing post__in to ensure our empty result takes precedence
            $args['post__in'] = [0];
            unset($args['post__not_in']); // Clear exclude as well
            return $args;
        }

        // Apply sorting dynamically
        $this->apply_sorting_to_query($args, $request);

        // Double-check: if any taxonomy filter was provided but doesn't match, return empty
        $tax_filters = $this->get_request_taxonomy_filters($request);
        foreach ($tax_filters as $param => $taxonomy_base) {
            $raw_value = $request->get_param($param);
            if (empty($raw_value)) {
                continue;
            }

            $taxonomy = $this->get_taxonomy_name($taxonomy_base);
            
            // If taxonomy doesn't exist but filter was provided, return empty
            if (!$taxonomy) {
                self::$has_invalid_filter = true;
                $args['post__in'] = [0];
                unset($args['post__not_in']); // Clear exclude as well
                return $args;
            }

            // If terms don't exist but filter was provided, return empty
            $terms = $this->parse_taxonomy_terms($raw_value, $taxonomy);
            if (empty($terms)) {
                self::$has_invalid_filter = true;
                $args['post__in'] = [0];
                unset($args['post__not_in']); // Clear exclude as well
                return $args;
            }
        }

        return $args;
    }

    /**
     * Modify query clauses as final check - runs during actual SQL query execution
     * This ensures we catch invalid filters even if other hooks don't fire
     */
    public function modify_query_clauses($clauses, $query) {
        // Only modify if this is a product query
        if (!isset($query->query_vars['post_type']) || $query->query_vars['post_type'] !== 'product') {
            return $clauses;
        }

        // Check if we're in a REST API context
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $clauses;
        }

        // First check: use static flag if invalid filter was detected
        if (self::$has_invalid_filter) {
            $clauses['where'] .= ' AND 1=0'; // Force no results
            return $clauses;
        }

        // Check if any taxonomy filter was provided but doesn't match
        global $wp_rest_request;
        if ($wp_rest_request instanceof WP_REST_Request) {
            $route = $wp_rest_request->get_route();
            if (strpos($route, '/wc/store/v1/products') !== false) {
                // Check for sort_options that might need taxonomy filtering
                $sort_options = $wp_rest_request->get_param('sort_options');
                $sort_option = $wp_rest_request->get_param('_sort_option');
                $sort_value = !empty($sort_option) ? $sort_option : $sort_options;
                
                if (!empty($sort_value)) {
                    // Get ordering args to check for tax_query
                    $ordering_args = apply_filters('woocommerce_get_catalog_ordering_args', [
                        'orderby' => $sort_value,
                        'order' => 'DESC',
                    ], $sort_value, 'DESC');
                    
                    // If ordering args include tax_query for product_visibility, apply it at SQL level
                    if (isset($ordering_args['tax_query']) && is_array($ordering_args['tax_query'])) {
                        foreach ($ordering_args['tax_query'] as $key => $tax_item) {
                            if ($key !== 'relation' && is_array($tax_item) && isset($tax_item['taxonomy']) && $tax_item['taxonomy'] === 'product_visibility' && !empty($tax_item['terms']) && is_array($tax_item['terms'])) {
                                global $wpdb;
                                $term_ids = $tax_item['terms'];
                                $term_taxonomy_ids = [];
                                foreach ($term_ids as $term_id) {
                                    $term_taxonomy_id = $wpdb->get_var($wpdb->prepare(
                                        "SELECT term_taxonomy_id FROM " . $wpdb->term_taxonomy . " WHERE term_id = %d AND taxonomy = %s",
                                        $term_id,
                                        'product_visibility'
                                    ));
                                    if ($term_taxonomy_id) {
                                        $term_taxonomy_ids[] = $term_taxonomy_id;
                                    }
                                }
                                if (!empty($term_taxonomy_ids)) {
                                    $placeholders = implode(',', array_fill(0, count($term_taxonomy_ids), '%d'));
                                    $alias = 'tr_sort_' . uniqid();
                                    if (strpos($clauses['join'], $alias) === false) {
                                        $clauses['join'] .= " INNER JOIN {$wpdb->term_relationships} AS {$alias} ON {$wpdb->posts}.ID = {$alias}.object_id";
                                    }
                                    $clauses['where'] .= $wpdb->prepare(" AND {$alias}.term_taxonomy_id IN ($placeholders)", ...$term_taxonomy_ids);
                                }
                            }
                        }
                    }
                }
                
                $tax_filters = $this->get_request_taxonomy_filters($wp_rest_request);
                foreach ($tax_filters as $param => $taxonomy_base) {
                    $raw_value = $wp_rest_request->get_param($param);
                    if (empty($raw_value)) {
                        continue;
                    }

                    $taxonomy = $this->get_taxonomy_name($taxonomy_base);
                    
                    // If taxonomy doesn't exist but filter was provided, force empty result
                    if (!$taxonomy) {
                        $clauses['where'] .= ' AND 1=0'; // Force no results
                        return $clauses;
                    }

                    // If terms don't exist but filter was provided, force empty result
                    $terms = $this->parse_taxonomy_terms($raw_value, $taxonomy);
                    if (empty($terms)) {
                        $clauses['where'] .= ' AND 1=0'; // Force no results
                        return $clauses;
                    }
                }
            }
        }

        return $clauses;
    }

    /**
     * Final fallback: Check response and return empty if invalid filters detected
     * This runs when preparing each product object in the response
     */
    public function check_response_for_invalid_filters($response, $post, $request) {
        // Only check once per request, not for each product
        static $checked = false;
        if ($checked) {
            return $response;
        }

        if (!$this->should_handle_request($request)) {
            return $response;
        }

        // If invalid filter was detected, we need to return empty response
        // But this hook runs per-item, so we'll use a different approach
        // Instead, we'll check in rest_pre_dispatch and return early response
        return $response;
    }

    private function should_handle_request($request) {
        if (!$request instanceof WP_REST_Request) {
            return false;
        }

        if ('GET' !== $request->get_method()) {
            return false;
        }

        $route = $request->get_route();
        if (!is_string($route)) {
            return false;
        }

        $normalized_route = trim($route, '/');
        $target           = trim(self::STORE_PRODUCTS_ROUTE, '/');

        return false !== strpos($normalized_route, $target);
    }

    /**
     * Convert color/size/fabric/region params into Store API tax params.
     */
    private function map_taxonomy_filters(WP_REST_Request $request) {
        $tax_filters = $this->get_request_taxonomy_filters($request);

        foreach ($tax_filters as $param => $taxonomy_base) {
            $raw_value = $request->get_param($param);
            if (empty($raw_value)) {
                continue;
            }

            $taxonomy = $this->get_taxonomy_name($taxonomy_base);
            if (!$taxonomy) {
                // Taxonomy doesn't exist - mark as invalid and return empty result
                self::$has_invalid_filter = true;
                $this->set_empty_result($request);
                return;
            }

            $terms = $this->parse_taxonomy_terms($raw_value, $taxonomy);
            if (empty($terms)) {
                // Terms don't exist - mark as invalid and return empty result
                self::$has_invalid_filter = true;
                $this->set_empty_result($request);
                return;
            }

            $param_name = $this->get_store_api_taxonomy_param($taxonomy);
            $existing   = $request->get_param($param_name);
            $existing   = is_array($existing) ? $existing : [];

            $request->set_param(
                $param_name,
                array_values(array_unique(array_merge($existing, $terms)))
            );
        }
    }

    /**
     * Set request to return empty results by setting include to non-existent ID
     * This ensures strict filtering - if a filter is provided but doesn't match, return empty
     */
    private function set_empty_result(WP_REST_Request $request) {
        // Set include to [0] which will return no products (product ID 0 doesn't exist)
        // This overrides any other filters and ensures empty results
        $request->set_param('include', [0]);
    }

    /**
     * Normalize list of stock statuses (Store API expects arrays).
     */
    private function normalize_stock_status(WP_REST_Request $request) {
        $value = $request->get_param('stock_status');
        if (empty($value)) {
            return;
        }

        $values     = $this->normalize_list_param($value);
        $valid_keys = array_keys(wc_get_product_stock_status_options());
        $values     = array_values(array_intersect($values, $valid_keys));

        if (!empty($values)) {
            $request->set_param('stock_status', $values);
        }
    }

    /**
     * Allow comma-separated category filters.
     */
    private function normalize_category_param(WP_REST_Request $request) {
        $value = $request->get_param('category');
        if (empty($value)) {
            return;
        }

        $request->set_param('category', $this->normalize_list_param($value));
    }

    /**
     * Strip currency symbols and keep numeric data for price filters.
     */
    private function normalize_price_filters(WP_REST_Request $request) {
        foreach (['min_price', 'max_price'] as $param) {
            $value = $request->get_param($param);
            if (null === $value || '' === $value) {
                continue;
            }

            $numeric = preg_replace('/[^0-9\.]/', '', (string) $value);
            if ($numeric === '') {
                continue;
            }

            $request->set_param($param, $numeric);
        }
    }

    /**
     * Convert comma separated strings into sanitized arrays.
     */
    private function normalize_list_param($value) {
        if (is_array($value)) {
            $list = $value;
        } elseif (is_string($value)) {
            $list = preg_split('/[,\|]/', $value);
        } else {
            $list = [(string) $value];
        }

        $list = array_filter(array_map(function ($item) {
            return sanitize_text_field(trim((string) $item));
        }, $list));

        return array_values(array_unique($list));
    }

    /**
     * Turn IDs/slugs/names into confirmed term IDs so Store API can use them.
     * Tries: term ID -> slug -> name (case-insensitive)
     */
    private function parse_taxonomy_terms($terms_value, $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms_array = $this->normalize_list_param($terms_value);
        $term_ids    = [];

        foreach ($terms_array as $term_value) {
            $term = null;
            
            // First try: numeric term ID
            if (is_numeric($term_value)) {
                $term = get_term((int) $term_value, $taxonomy);
            } else {
                // Second try: slug (exact match)
                $slug = sanitize_title($term_value);
                $term = get_term_by('slug', $slug, $taxonomy);
                
                // Third try: name (case-insensitive) if slug didn't work
                if (!$term || is_wp_error($term)) {
                    $term = get_term_by('name', $term_value, $taxonomy);
                }
            }

            if ($term && !is_wp_error($term)) {
                $term_ids[] = (int) $term->term_id;
            }
        }

        return array_values(array_unique(array_filter($term_ids)));
    }

    /**
     * Resolve taxonomy names (pa_color, product_color, color, etc.).
     */
    private function get_taxonomy_name($base_name) {
        $possible_names = [
            'pa_' . $base_name,
            'product_' . $base_name,
            $base_name,
        ];

        foreach ($possible_names as $taxonomy_name) {
            if (taxonomy_exists($taxonomy_name)) {
                return $taxonomy_name;
            }
        }

        return false;
    }

    private function get_store_api_taxonomy_param($taxonomy) {
        return '_unstable_tax_' . $taxonomy;
    }

    /**
     * Discover taxonomy filters dynamically from request params.
     */
    private function get_request_taxonomy_filters(WP_REST_Request $request): array {
        $params = $request->get_params();
        if (empty($params) || !is_array($params)) {
            return [];
        }

        $filters = [];
        foreach ($params as $param => $value) {
            if (!is_string($param) || $param === '') {
                continue;
            }

            if ($this->is_reserved_param($param)) {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // Direct match (color => pa_color)
            $taxonomy = $this->get_taxonomy_name($param);
            if ($taxonomy) {
                $filters[$param] = $param;
                continue;
            }

            // Allow specifying taxonomy name directly (pa_color) or product_color
            $base = str_replace(['pa_', 'product_'], '', $param);
            if ($base !== $param) {
                $taxonomy = $this->get_taxonomy_name($base);
                if ($taxonomy) {
                    $filters[$param] = $base;
                }
            }
        }

        return $filters;
    }

    private function is_reserved_param(string $param): bool {
        $reserved = [
            'page', 'per_page', 'search', 'after', 'before', 'order', 'orderby',
            'sort_options', '_sort_option', 'include', 'exclude', 'offset',
            'menu_order', 'status', 'type', 'tag', 'tag_id', 'category',
            'category_operator', 'sku', 'featured', 'on_sale', 'min_price',
            'max_price', 'stock_status', 'stock_statuses', 'catalog_visibility',
            'slug', '_fields', '_locale', '_embed', 'attributes', 'attribute_term',
            'match'
        ];

        if (in_array($param, $reserved, true)) {
            return true;
        }

        if (strpos($param, '_unstable_') === 0 || strpos($param, '_') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Normalize sorting parameter - support both 'orderby' and 'sort_options'
     * Store the sort option for dynamic processing
     */
    private function normalize_sorting_param(WP_REST_Request $request) {
        $sort_options = $request->get_param('sort_options');
        $orderby = $request->get_param('orderby');
        
        // Determine which parameter to use
        $sort_value = !empty($sort_options) ? $sort_options : $orderby;
        
        if (empty($sort_value)) {
            return;
        }
        
        $sort_value = sanitize_text_field($sort_value);
        
        // Store original sort option for dynamic processing
        $request->set_param('_sort_option', $sort_value);
        
        // Set orderby for Store API validation (use a valid default if needed)
        // WooCommerce will handle the actual sorting dynamically
        $valid_orderby = ['date', 'modified', 'id', 'include', 'title', 'slug', 'price', 'popularity', 'rating', 'menu_order', 'comment_count'];
        if (in_array($sort_value, $valid_orderby)) {
            $request->set_param('orderby', $sort_value);
        } else {
            // For custom sort options, get ordering args to check if it needs taxonomy filter
            $ordering_args = apply_filters('woocommerce_get_catalog_ordering_args', [
                'orderby' => $sort_value,
                'order' => 'DESC',
            ], $sort_value, 'DESC');
            
            // If ordering args include tax_query, extract and set Store API taxonomy parameters dynamically
            if (isset($ordering_args['tax_query']) && is_array($ordering_args['tax_query'])) {
                foreach ($ordering_args['tax_query'] as $key => $tax_item) {
                    // Skip 'relation' key, only process actual tax query items
                    if ($key !== 'relation' && is_array($tax_item) && isset($tax_item['taxonomy']) && !empty($tax_item['terms']) && is_array($tax_item['terms'])) {
                        $taxonomy = $tax_item['taxonomy'];
                        $tax_param = $this->get_store_api_taxonomy_param($taxonomy);
                        $existing = $request->get_param($tax_param);
                        $existing = is_array($existing) ? $existing : [];
                        $existing = array_merge($existing, $tax_item['terms']);
                        $request->set_param($tax_param, array_values(array_unique($existing)));
                    }
                }
            }
            
            // Use 'date' as fallback for validation
            $request->set_param('orderby', 'date');
        }
    }

    /**
     * Apply sorting to query args - fully dynamic using WooCommerce's catalog ordering
     */
    private function apply_sorting_to_query(&$args, WP_REST_Request $request) {
        $sort_option = $request->get_param('_sort_option');
        $orderby = $request->get_param('orderby');
        $sort_options = $request->get_param('sort_options');
        
        // Determine the sort value
        $sort_value = !empty($sort_option) ? $sort_option : (!empty($orderby) ? $orderby : $sort_options);
        
        if (empty($sort_value)) {
            return;
        }
        
        $sort_value = sanitize_text_field($sort_value);
        $order = $request->get_param('order');
        if (empty($order)) {
            $order = 'DESC';
        } else {
            $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        }
        
        // Use WooCommerce's dynamic catalog ordering for ALL sort options
        $ordering_args = apply_filters('woocommerce_get_catalog_ordering_args', [
            'orderby' => $sort_value,
            'order' => $order,
        ], $sort_value, $order);
        
        // Merge ordering args dynamically
        if (isset($ordering_args['orderby'])) {
            $args['orderby'] = $ordering_args['orderby'];
        }
        if (isset($ordering_args['order'])) {
            $args['order'] = $ordering_args['order'];
        }
        if (isset($ordering_args['meta_key'])) {
            $args['meta_key'] = $ordering_args['meta_key'];
        }
        if (isset($ordering_args['meta_query']) && is_array($ordering_args['meta_query'])) {
            if (!isset($args['meta_query'])) {
                $args['meta_query'] = [];
            }
            $args['meta_query'] = array_merge($args['meta_query'], $ordering_args['meta_query']);
        }
        if (isset($ordering_args['tax_query']) && is_array($ordering_args['tax_query'])) {
            // Extract relation from ordering_args if exists
            $relation = 'AND';
            if (isset($ordering_args['tax_query']['relation'])) {
                $relation = $ordering_args['tax_query']['relation'];
            }
            
            // Initialize args tax_query if needed
            if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
                $args['tax_query'] = [];
            }
            
            // Extract existing relation from args if exists
            $existing_relation = 'AND';
            if (isset($args['tax_query']['relation'])) {
                $existing_relation = $args['tax_query']['relation'];
                unset($args['tax_query']['relation']);
            }
            
            // Use the relation from ordering_args if provided, otherwise use existing
            $final_relation = !empty($ordering_args['tax_query']['relation']) ? $relation : $existing_relation;
            
            // Merge tax_query items (skip 'relation' key)
            foreach ($ordering_args['tax_query'] as $key => $tax_item) {
                if ($key !== 'relation' && is_array($tax_item)) {
                    $args['tax_query'][] = $tax_item;
                }
            }
            
            // Re-add relation at the beginning if we have tax_query items
            if (!empty($args['tax_query'])) {
                $args['tax_query'] = array_merge(['relation' => $final_relation], $args['tax_query']);
            }
        }
    }

    /**
     * Handle sorting and add sort_options to response
     */
    public function handle_sorting_and_response($response, $server, $request) {
        if (!$request instanceof WP_REST_Request) {
            return $response;
        }

        if (!($response instanceof WP_REST_Response)) {
            return $response;
        }

        try {
            $route = $request->get_route();
        } catch (Exception $e) {
            return $response;
        }
        
        // Only handle Store API products list endpoint
        if (empty($route) || !is_string($route)) {
            return $response;
        }
        
        if ($route !== '/wc/store/v1/products' && strpos($route, '/wc/store/v1/products') !== 0) {
            return $response;
        }
        
        // Don't modify single product endpoints
        if (preg_match('#/wc/store/v1/products/(\d+)#', $route)) {
            return $response;
        }

        try {
            $data = $response->get_data();
        } catch (Exception $e) {
            return $response;
        } catch (Error $e) {
            return $response;
        }
        
        if (!is_array($data)) {
            return $response;
        }
        
        // Check if this is a collection (array of products)
        $is_collection = false;
        
        if (!empty($data) && is_array($data)) {
            // Check if data is directly an array of products
            $first_item = reset($data);
            if (is_array($first_item) && isset($first_item['id'])) {
                $is_collection = true;
            }
            // Check if data has a 'products' key
            elseif (isset($data['products']) && is_array($data['products'])) {
                $is_collection = true;
            }
        }
        
        // Add sort_options to response
        if ($is_collection) {
            // If data was directly an array, wrap it
            if (isset($data[0]) && is_array($data[0]) && isset($data[0]['id'])) {
                $response->set_data([
                    'products' => $data,
                    'sort_options' => $this->get_sort_options(),
                ]);
            } else {
                // Data already has structure, just add sort_options
                $data['sort_options'] = $this->get_sort_options();
                $response->set_data($data);
            }
        } else {
            $data['sort_options'] = $this->get_sort_options();
            $response->set_data($data);
        }

        return $response;
    }

    /**
     * Get all available sort options for products
     *
     * @return array Array of sort options with value and label
     */
    private function get_sort_options() {
        if (!function_exists('wc_get_default_product_orderby_options')) {
            return [];
        }

        // Get default WooCommerce sort options
        $default_options = wc_get_default_product_orderby_options();
        
        // Get custom sort options (including our "featured" option)
        $custom_options = apply_filters('woocommerce_catalog_orderby', $default_options);
        
        // Format for API response
        $sort_options = [];
        foreach ($custom_options as $key => $label) {
            $sort_options[] = [
                'value' => $key,
                'label' => $label,
            ];
        }
        
        return $sort_options;
    }
}
