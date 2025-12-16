<?php

class RESTBridge_Search_API {

    public function register_routes() {
        // Single unified search endpoint for entire site
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                's' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Search query',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 20,
                    'description' => 'Number of results per page',
                ],
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'description' => 'Page number',
                ],
            ],
        ]);
    }

    /**
     * Unified search across entire site
     * GET /wp-json/restbridge/v1/search?s=test&per_page=20&page=1
     * 
     * Searches across:
     * - Posts (blog, articles)
     * - Pages
     * - Products (if WooCommerce active)
     * - Custom content
     */
    public function search(WP_REST_Request $request) {
        $query = $request->get_param('s');
        $per_page = $request->get_param('per_page') ?: 20;
        $page = $request->get_param('page') ?: 1;

        if (empty($query) || strlen($query) < 1) {
            return new WP_Error('empty_query', 'Search query (s) is required', ['status' => 400]);
        }

        // Build post types array - includes all public post types
        $post_types = ['post', 'page'];
        
        // Add WooCommerce products if available
        if (class_exists('WooCommerce')) {
            $post_types[] = 'product';
        }
        
        // Add any custom post types registered with 'publicly_queryable' => true
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'names');
        if (!empty($custom_post_types)) {
            $post_types = array_merge($post_types, $custom_post_types);
        }

        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            's' => $query,
            'posts_per_page' => (int) $per_page,
            'paged' => (int) $page,
            'orderby' => 'relevance',
        ];

        $wp_query = new WP_Query($args);
        $results = [];

        while ($wp_query->have_posts()) {
            $wp_query->the_post();
            $post = get_post();

            $result = [
                'id' => $post->ID,
                'title' => get_the_title($post->ID),
                'excerpt' => get_the_excerpt($post->ID),
                'content' => substr(wp_strip_all_tags($post->post_content), 0, 300),
                'type' => $post->post_type,
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'permalink' => get_permalink($post->ID),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
            ];

            // Add type-specific data
            if ($post->post_type === 'product') {
                $product = wc_get_product($post->ID);
                if ($product) {
                    $result['price'] = $product->get_price();
                    $result['rating'] = $product->get_average_rating();
                    $result['stock'] = $product->get_stock_quantity();
                }
            } else {
                // For posts and pages, add categories and tags
                $result['categories'] = wp_get_post_categories($post->ID);
                $result['tags'] = wp_get_post_tags($post->ID, ['fields' => 'ids']);
            }

            $results[] = $result;
        }

        wp_reset_postdata();

        return rest_ensure_response([
            'query' => $query,
            'results' => $results,
            'total' => (int) $wp_query->found_posts,
            'pages' => (int) $wp_query->max_num_pages,
            'current_page' => (int) $page,
            'per_page' => (int) $per_page,
        ]);
    }
}

