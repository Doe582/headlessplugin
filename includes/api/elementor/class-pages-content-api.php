<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Pages_Content_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        // Get all pages (both Elementor and Gutenberg)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/content/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content'],
            'permission_callback' => '__return_true',
        ]);

        // Get page content by slug (both Elementor and Gutenberg)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/content/slug/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content_by_slug'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get page content (auto-detect Elementor or Gutenberg)
     */
    public function get_page_content(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $page = get_post($page_id);

        if (!$page) {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        // Check for Elementor first
        $elementor_data = get_post_meta($page_id, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $response = $this->parse_elementor_content($page, $elementor_data);
            $response['editor'] = 'elementor';
            return rest_ensure_response($response);
        }

        // Check for Gutenberg
        $content = $page->post_content;
        if (!empty($content) && strpos($content, '<!-- wp:') !== false) {
            return rest_ensure_response($this->parse_gutenberg_content($page, $content));
        }

        return new WP_Error('no_content', 'This page does not use Elementor or Gutenberg', ['status' => 404]);
    }

    /**
     * Get page content by slug (auto-detect Elementor or Gutenberg)
     */
    public function get_page_content_by_slug(WP_REST_Request $request) {
        $slug = sanitize_text_field($request['slug']);
        $page = get_page_by_path($slug);

        if (!$page) {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        // Check for Elementor first
        $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
        if (!empty($elementor_data)) {
            $response = $this->parse_elementor_content($page, $elementor_data);
            $response['editor'] = 'elementor';
            return rest_ensure_response($response);
        }

        // Check for Gutenberg
        $content = $page->post_content;
        if (!empty($content) && strpos($content, '<!-- wp:') !== false) {
            return rest_ensure_response($this->parse_gutenberg_content($page, $content));
        }

        return new WP_Error('no_content', 'This page does not use Elementor or Gutenberg', ['status' => 404]);
    }
}

