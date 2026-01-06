<?php

class RESTBridge_Search_API {

    /**
     * Register REST routes
     */
    public function register_routes() {

        register_rest_route(
            RESTBRIDGE_API_NAMESPACE,
            '/search(?:/(?P<type>[a-zA-Z0-9_-]+))?',
            [
                'methods'  => 'GET',
                'callback' => [$this, 'search'],
                'permission_callback' => '__return_true',
                'args' => [
                    's' => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 10, // ✅ default pagination
                    ],
                    'page' => [
                        'type' => 'integer',
                        'default' => 1,
                    ],
                    'type' => [
                        'type' => 'string',
                        'required' => false,
                    ],
                ],
            ]
        );
    }

    /**
     * Generate limited excerpt + detect "Read more"
     */
    private function get_excerpt_data($excerpt, $content, $limit = 120) {

        $content_clean = wp_strip_all_tags($content);
        $excerpt_clean = wp_strip_all_tags($excerpt);

        // Display text source
        $source_text = $excerpt_clean ?: $content_clean;

        // Decide read more ONLY using full content length
        $has_more = strlen($content_clean) > $limit;

        if (strlen($source_text) > $limit) {
            $source_text = substr($source_text, 0, $limit) . '...';
        }

        return [
            'text'     => $source_text,
            'has_more' => $has_more,
        ];
    }

    /**
     * Unified search API
     * GET /wp-json/restbridge/v1/search?s=test&page=1&per_page=10
     */
    public function search(WP_REST_Request $request) {

        $query    = $request->get_param('s');
        $per_page = (int) ($request->get_param('per_page') ?: 10);
        $page     = (int) ($request->get_param('page') ?: 1);
        $type     = sanitize_key($request->get_param('type'));

        if (empty($query)) {
            return new WP_Error(
                'empty_query',
                'Search query (s) is required',
                ['status' => 400]
            );
        }

        /**
         * Resolve post types
         */
        if (!empty($type)) {

            if ($type === 'product' && class_exists('WooCommerce')) {
                $post_types = ['product'];
            } elseif (post_type_exists($type)) {
                $post_types = [$type];
            } else {
                return new WP_Error(
                    'invalid_type',
                    'Invalid search type',
                    ['status' => 400]
                );
            }

        } else {

            $post_types = ['post', 'page'];

            if (class_exists('WooCommerce')) {
                $post_types[] = 'product';
            }

            $custom_post_types = get_post_types(
                ['public' => true, '_builtin' => false],
                'names'
            );

            if (!empty($custom_post_types)) {
                $post_types = array_merge($post_types, $custom_post_types);
            }
        }

        /**
         * WP Query
         */
        $args = [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            's'              => $query,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'relevance',
        ];

        $wp_query = new WP_Query($args);
        $results  = [];

        while ($wp_query->have_posts()) {
            $wp_query->the_post();
            $post = get_post();

            // Excerpt logic
            $excerpt_data = $this->get_excerpt_data(
                get_the_excerpt($post->ID),
                $post->post_content,
                120
            );

            $item = [
                'id'        => $post->ID,
                'title'     => get_the_title($post->ID),
                'excerpt'   => $excerpt_data['text'],
                'has_more'  => $excerpt_data['has_more'], // ✅ Read more flag
                'type'      => $post->post_type,
                'date'      => $post->post_date,
                'modified'  => $post->post_modified,
                'permalink' => get_permalink($post->ID),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
            ];

            /**
             * Product-specific data
             */
            if ($post->post_type === 'product' && class_exists('WooCommerce')) {

                $product = wc_get_product($post->ID);

                if ($product) {
                    $item['price']  = $product->get_price();
                    $item['rating'] = $product->get_average_rating();
                    $item['stock']  = $product->get_stock_quantity();
                }

            } else {

                $item['categories'] = wp_get_post_categories($post->ID);
                $item['tags'] = wp_get_post_tags($post->ID, ['fields' => 'ids']);
            }

            $results[] = $item;
        }

        wp_reset_postdata();

        return rest_ensure_response([
            'query'        => $query,
            'type'         => $type ?: 'all',
            'results'      => $results,
            'total'        => (int) $wp_query->found_posts,
            'pages'        => (int) $wp_query->max_num_pages,
            'current_page' => $page,
            'per_page'     => $per_page,
        ]);
    }
}
