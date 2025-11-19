<?php

/**
 * Custom WooCommerce Product Sorting
 * Adds "Sort by Featured" option to product catalog
 */
class RESTBridge_WooCommerce_Product_Sorting {

    public function __construct() {
        // Add "Featured" option to catalog sorting dropdown
        add_filter('woocommerce_catalog_orderby', [$this, 'add_featured_sort_option']);
        
        // Handle the featured sorting logic
        add_filter('woocommerce_get_catalog_ordering_args', [$this, 'handle_featured_sorting'], 10, 3);
        
        // Handle featured sorting in main query
        add_action('pre_get_posts', [$this, 'handle_pre_get_posts']);
    }

    /**
     * Add "Featured" option to catalog sorting dropdown
     *
     * @param array $sortby Existing sort options
     * @return array Modified sort options
     */
    public function add_featured_sort_option($sortby) {
        $sortby['featured'] = 'Sort by Featured';
        return $sortby;
    }

    /**
     * Handle featured sorting arguments
     *
     * @param array $args Query arguments
     * @param string $orderby Order by parameter
     * @param string $order Order direction
     * @return array Modified query arguments
     */
    public function handle_featured_sorting($args, $orderby, $order) {
        if ($orderby === 'featured') {
            $visibility = wc_get_product_visibility_term_ids();

            // Initialize tax_query if it doesn't exist
            if (!isset($args['tax_query']) || !is_array($args['tax_query'])) {
                $args['tax_query'] = [];
            }
            
            // Extract relation if exists
            $relation = 'AND';
            if (isset($args['tax_query']['relation'])) {
                $relation = $args['tax_query']['relation'];
                unset($args['tax_query']['relation']);
            }

            // Add featured filter
            $args['tax_query'][] = [
                'taxonomy' => 'product_visibility',
                'field'    => 'term_id',
                'terms'    => [$visibility['featured']],
                'operator' => 'IN',
            ];
            
            // Re-add relation
            if (!empty($args['tax_query'])) {
                $args['tax_query'] = array_merge(['relation' => $relation], $args['tax_query']);
            }

            // Sort inside featured group by newest first
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        }

        return $args;
    }

    /**
     * Handle featured sorting in main query
     *
     * @param WP_Query $query WordPress query object
     * @return void
     */
    public function handle_pre_get_posts($query) {
        if (!is_admin() && $query->is_main_query() && isset($_GET['orderby']) && $_GET['orderby'] === 'featured') {
            $visibility = wc_get_product_visibility_term_ids();

            $query->set('tax_query', [
                [
                    'taxonomy' => 'product_visibility',
                    'field'    => 'term_id',
                    'terms'    => $visibility['featured'],
                ]
            ]);

            $query->set('orderby', 'date');
            $query->set('order', 'DESC');
        }
    }
}

