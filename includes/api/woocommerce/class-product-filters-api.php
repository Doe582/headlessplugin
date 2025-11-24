<?php

class RESTBridge_Product_Filters_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/product-filters', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_filters'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_product_filters(WP_REST_Request $request) {
        $filters = [
            'categories' => $this->get_categories(),
            'price_range' => $this->get_price_range(),
            'taxonomies' => $this->get_dynamic_taxonomies(),
            'additional_filters' => $this->get_additional_filters(),
        ];

        return rest_ensure_response($filters);
    }

    /**
     * Get product categories with counts
     */
    private function get_categories() {
        $categories = [];
        
        if (!taxonomy_exists('product_cat')) {
            return $categories;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'parent' => 0, // Only top-level categories
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return $categories;
        }

        foreach ($terms as $term) {
            $categories[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'count' => $term->count,
            ];
        }

        return $categories;
    }

    /**
     * Get price range (min/max) from all products
     */
    private function get_price_range() {
        global $wpdb;

        $min_price = $wpdb->get_var("
            SELECT MIN(meta_value + 0)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_price'
            AND meta_value != ''
        ");

        $max_price = $wpdb->get_var("
            SELECT MAX(meta_value + 0)
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_price'
            AND meta_value != ''
        ");

        // Also check variation prices for variable products
        $variation_min = $wpdb->get_var("
            SELECT MIN(meta_value + 0)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_price'
            AND pm.meta_value != ''
            AND p.post_type = 'product_variation'
            AND p.post_status = 'publish'
        ");

        $variation_max = $wpdb->get_var("
            SELECT MAX(meta_value + 0)
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_price'
            AND pm.meta_value != ''
            AND p.post_type = 'product_variation'
            AND p.post_status = 'publish'
        ");

        $min = min(
            $min_price ? (float) $min_price : PHP_FLOAT_MAX,
            $variation_min ? (float) $variation_min : PHP_FLOAT_MAX
        );
        $max = max(
            $max_price ? (float) $max_price : 0,
            $variation_max ? (float) $variation_max : 0
        );

        // Format prices
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '';
        $currency_code = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';

        return [
            'min' => $min === PHP_FLOAT_MAX ? 0 : (float) $min,
            'max' => (float) $max,
            'currency_symbol' => $currency_symbol,
            'currency_code' => $currency_code,
        ];
    }

    /**
     * Get all dynamic taxonomies for products
     * Automatically detects any taxonomy registered for products
     */
    private function get_dynamic_taxonomies() {
        $dynamic_taxonomies = [];
        
        // Get all taxonomies registered for products
        $taxonomies = get_taxonomies(['object_type' => ['product']], 'objects');
        
        if (empty($taxonomies)) {
            return $dynamic_taxonomies;
        }

        // System taxonomies to exclude (already handled separately or not for filtering)
        $excluded_taxonomies = [
            'product_cat',           // Handled in categories
            'product_tag',           // Usually not used for filtering
            'product_shipping_class', // Not for product filtering
            'product_visibility',     // Internal taxonomy
            'product_type',          // Internal taxonomy
            'product_translation',   // WPML taxonomy
        ];

        foreach ($taxonomies as $taxonomy_name => $taxonomy_obj) {
            // Skip excluded taxonomies
            if (in_array($taxonomy_name, $excluded_taxonomies, true)) {
                continue;
            }

            // Get terms for this taxonomy
            $terms = get_terms([
                'taxonomy' => $taxonomy_name,
                'hide_empty' => true,
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            // Check if this is a color-related taxonomy
            $is_color_taxonomy = $this->is_color_taxonomy($taxonomy_name, $taxonomy_obj);

            $taxonomy_terms = [];
            foreach ($terms as $term) {
                $term_data = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => $term->count,
                ];

                $size_number = $this->get_size_number_from_term($term);
                if ($size_number !== null) {
                    $term_data['size_number'] = $size_number;
                }

                // Add color value if it's a color taxonomy
                if ($is_color_taxonomy) {
                    $term_data['color'] = $this->extract_color_from_term($term);
                }

                $taxonomy_terms[] = $term_data;
            }

            if (!empty($taxonomy_terms)) {
                $dynamic_taxonomies[] = [
                    'taxonomy' => $taxonomy_name,
                    'label' => $taxonomy_obj->label ?? $taxonomy_name,
                    'singular_label' => $taxonomy_obj->labels->singular_name ?? $taxonomy_name,
                    'hierarchical' => $taxonomy_obj->hierarchical ?? false,
                    'terms' => $taxonomy_terms,
                ];
            }
        }

        return $dynamic_taxonomies;
    }

    /**
     * Check if a taxonomy is color-related
     */
    private function is_color_taxonomy($taxonomy_name, $taxonomy_obj) {
        $name_lower = strtolower($taxonomy_name);
        $label_lower = strtolower($taxonomy_obj->label ?? '');
        
        return (
            strpos($name_lower, 'color') !== false ||
            strpos($name_lower, 'colour') !== false ||
            strpos($label_lower, 'color') !== false ||
            strpos($label_lower, 'colour') !== false
        );
    }

    /**
     * Read numeric size metadata stored on taxonomy terms.
     */
    private function get_size_number_from_term($term) {
        if (!$term || !isset($term->term_id)) {
            return null;
        }

        $value = get_term_meta($term->term_id, '_styluza_wc_size_number', true);
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $value = (string) (0 + $value);
        }

        return sanitize_text_field((string) $value);
    }

    /**
     * Get additional filter options (In Stock Only, On Sale)
     */
    private function get_additional_filters() {
        global $wpdb;

        // Count in stock products
        $in_stock_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_stock_status'
            AND pm.meta_value = 'instock'
        ");

        // Count on sale products
        $on_sale_count = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_key = '_sale_price'
            AND pm.meta_value != ''
            AND pm.meta_value > 0
        ");

        return [
            [
                'id' => 'in_stock',
                'name' => 'In Stock Only',
                'slug' => 'in_stock',
                'count' => (int) $in_stock_count,
            ],
            [
                'id' => 'on_sale',
                'name' => 'On Sale',
                'slug' => 'on_sale',
                'count' => (int) $on_sale_count,
            ],
        ];
    }

    /**
     * Extract color value from term (similar to class-store-api-product-detail.php)
     */
    private function extract_color_from_term($term) {
        if (!$term) {
            return $this->resolve_color_from_name('');
        }

        $meta_keys = [
            'product_attribute_color',
            'color',
            'pa_color',
            'styluza_color_value',
            '_color',
            '_styluza_wc_color',
        ];

        foreach ($meta_keys as $key) {
            $value = get_term_meta($term->term_id, $key, true);
            if ($this->is_valid_hex_color($value)) {
                return strtoupper($value);
            }
        }

        // Check custom color map
        $custom_map = $this->get_custom_color_map();
        $term_name = strtolower($term->name);
        if (isset($custom_map[$term_name])) {
            return strtoupper($custom_map[$term_name]);
        }

        return $this->resolve_color_from_name($term->name);
    }

    /**
     * Resolve color from name
     */
    private function resolve_color_from_name($name) {
        if ($name === '') {
            return '#CCCCCC';
        }

        if ($this->is_valid_hex_color($name)) {
            return strtoupper($name);
        }

        $custom_map = $this->get_custom_color_map();
        $key = strtolower($name);
        if (isset($custom_map[$key])) {
            return strtoupper($custom_map[$key]);
        }

        return $this->generate_color_from_name($name);
    }

    /**
     * Get custom color map
     */
    private function get_custom_color_map() {
        $map = get_option('styluza_custom_color_map', []);
        if (!is_array($map)) {
            $map = [];
        }

        $normalized = [];
        foreach ($map as $key => $value) {
            if (!$this->is_valid_hex_color($value)) {
                continue;
            }
            $normalized[strtolower($key)] = $value;
        }

        return $normalized;
    }

    /**
     * Check if string is a valid hex color
     */
    private function is_valid_hex_color($value) {
        return is_string($value) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', trim($value));
    }

    /**
     * Generate deterministic color hex based on name
     */
    private function generate_color_from_name($name) {
        $hash = md5(strtolower($name));
        $color = '#' . substr($hash, 0, 6);
        return strtoupper($color);
    }
}

