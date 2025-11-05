<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Gutenberg_Pages_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        // Get all Gutenberg pages
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/gutenberg/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_gutenberg_pages'],
            'permission_callback' => '__return_true',
        ]);

        // Get Gutenberg content for a specific page
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/gutenberg/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_gutenberg_page_content'],
            'permission_callback' => '__return_true',
        ]);

        // Get Gutenberg content for a page by slug
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/gutenberg/pages/slug/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_gutenberg_page_by_slug'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get all pages that use Gutenberg
     */
    public function get_gutenberg_pages(WP_REST_Request $request) {
        $params = $request->get_query_params();
        
        $args = [
            'post_type' => 'page',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : -1,
            'paged' => isset($params['page']) ? (int) $params['page'] : 1,
            'meta_query' => [
                [
                    'key' => '_elementor_data',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        if (isset($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        $query = new WP_Query($args);
        $pages = [];

        while ($query->have_posts()) {
            $query->the_post();
            $page = get_post();
            
            // Only include pages with Gutenberg blocks
            $content = $page->post_content;
            if (strpos($content, '<!-- wp:') !== false) {
                $pages[] = [
                    'id' => $page->ID,
                    'title' => get_the_title($page->ID),
                    'slug' => $page->post_name,
                    'permalink' => get_permalink($page->ID),
                    'status' => $page->post_status,
                    'modified' => $page->post_modified,
                ];
            }
        }
        wp_reset_postdata();

        return rest_ensure_response([
            'pages' => $pages,
            'total' => count($pages),
            'pages_count' => $query->max_num_pages,
        ]);
    }

    /**
     * Get Gutenberg content for a specific page by ID
     */
    public function get_gutenberg_page_content(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $page = get_post($page_id);

        if (!$page) {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        $content = $page->post_content;
        
        if (empty($content) || strpos($content, '<!-- wp:') === false) {
            return new WP_Error('no_gutenberg_content', 'This page does not use Gutenberg blocks', ['status' => 404]);
        }

        return rest_ensure_response($this->parse_gutenberg_content($page, $content));
    }

    /**
     * Get Gutenberg content for a page by slug
     */
    public function get_gutenberg_page_by_slug(WP_REST_Request $request) {
        $slug = sanitize_text_field($request['slug']);
        $page = get_page_by_path($slug);

        if (!$page) {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        $content = $page->post_content;
        
        if (empty($content) || strpos($content, '<!-- wp:') === false) {
            return new WP_Error('no_gutenberg_content', 'This page does not use Gutenberg blocks', ['status' => 404]);
        }

        return rest_ensure_response($this->parse_gutenberg_content($page, $content));
    }
}

