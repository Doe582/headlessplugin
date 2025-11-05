<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Footer_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        // Footer API endpoints
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footer', [
            'methods' => 'GET',
            'callback' => [$this, 'get_footer'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footer/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_footer_by_id'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footers', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_footers'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get active footer (Elementor Theme Builder or Gutenberg template part)
     */
    public function get_footer(WP_REST_Request $request) {
        // Try to get active Elementor footer template
        $footer = $this->get_active_elementor_template('footer');
        
        if ($footer) {
            return rest_ensure_response($footer);
        }

        // Try to get Gutenberg footer template part
        $gutenberg_footer = $this->get_gutenberg_template_part('footer');
        if ($gutenberg_footer) {
            return rest_ensure_response($gutenberg_footer);
        }

        // Try to get theme footer widget area
        $theme_footer = $this->get_theme_footer();
        if ($theme_footer) {
            return rest_ensure_response($theme_footer);
        }

        return new WP_Error('no_footer', 'No footer found', ['status' => 404]);
    }

    /**
     * Get footer by ID
     */
    public function get_footer_by_id(WP_REST_Request $request) {
        $footer_id = (int) $request['id'];
        $footer = get_post($footer_id);

        if (!$footer) {
            return new WP_Error('footer_not_found', 'Footer not found', ['status' => 404]);
        }

        // Check if it's an Elementor template
        $template_type = get_post_meta($footer_id, '_elementor_template_type', true);
        if ($template_type === 'footer') {
            $elementor_data = get_post_meta($footer_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $response = $this->parse_elementor_content($footer, $elementor_data);
                $response['type'] = 'elementor';
                $response['template_id'] = $footer_id;
                return rest_ensure_response($response);
            }
        }

        // Check if it's Gutenberg content
        $content = $footer->post_content;
        if (!empty($content) && strpos($content, '<!-- wp:') !== false) {
            $response = $this->parse_gutenberg_content($footer, $content);
            $response['type'] = 'gutenberg';
            $response['template_id'] = $footer_id;
            return rest_ensure_response($response);
        }

        return new WP_Error('invalid_footer', 'Footer template not found or invalid', ['status' => 404]);
    }

    /**
     * Get all footers
     */
    public function get_all_footers(WP_REST_Request $request) {
        $footers = [];

        // Get Elementor footers
        $elementor_footers = $this->get_elementor_templates('footer');
        foreach ($elementor_footers as $footer) {
            $footers[] = [
                'id' => $footer->ID,
                'title' => get_the_title($footer->ID),
                'slug' => $footer->post_name,
                'type' => 'elementor',
                'status' => $footer->post_status,
                'modified' => $footer->post_modified,
            ];
        }

        // Get Gutenberg template parts
        $gutenberg_footers = $this->get_gutenberg_template_parts('footer');
        foreach ($gutenberg_footers as $footer) {
            $footers[] = [
                'id' => $footer->ID,
                'title' => get_the_title($footer->ID),
                'slug' => $footer->post_name,
                'type' => 'gutenberg',
                'status' => $footer->post_status,
                'modified' => $footer->post_modified,
            ];
        }

        return rest_ensure_response([
            'footers' => $footers,
            'total' => count($footers),
        ]);
    }

    /**
     * Get Elementor templates by type
     */
    private function get_elementor_templates($type) {
        $args = [
            'post_type' => 'elementor_library',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_elementor_template_type',
                    'value' => $type,
                    'compare' => '='
                ]
            ]
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Get active Elementor template (handles conditions)
     */
    private function get_active_elementor_template($type) {
        // Get all templates of this type
        $templates = $this->get_elementor_templates($type);
        
        if (empty($templates)) {
            return null;
        }

        // Try to find active template using Elementor conditions
        // For now, return the first published template
        foreach ($templates as $template) {
            if ($template->post_status === 'publish') {
                $elementor_data = get_post_meta($template->ID, '_elementor_data', true);
                if (!empty($elementor_data)) {
                    $response = $this->parse_elementor_content($template, $elementor_data);
                    $response['type'] = 'elementor';
                    $response['template_id'] = $template->ID;
                    $response['template_title'] = get_the_title($template->ID);
                    return $response;
                }
            }
        }

        return null;
    }

    /**
     * Get Gutenberg template parts by area
     */
    private function get_gutenberg_template_parts($area) {
        $args = [
            'post_type' => 'wp_template_part',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'wp_theme',
                    'field' => 'slug',
                    'terms' => get_stylesheet(),
                ],
                [
                    'taxonomy' => 'wp_template_part_area',
                    'field' => 'slug',
                    'terms' => $area,
                ]
            ]
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Get active Gutenberg template part
     */
    private function get_gutenberg_template_part($area) {
        $template_parts = $this->get_gutenberg_template_parts($area);
        
        if (empty($template_parts)) {
            return null;
        }

        // Return the first published template part
        foreach ($template_parts as $template_part) {
            if ($template_part->post_status === 'publish') {
                $content = $template_part->post_content;
                if (!empty($content)) {
                    $response = $this->parse_gutenberg_content($template_part, $content);
                    $response['type'] = 'gutenberg';
                    $response['template_id'] = $template_part->ID;
                    $response['template_title'] = get_the_title($template_part->ID);
                    return $response;
                }
            }
        }

        return null;
    }

    /**
     * Get theme footer (fallback for non-Elementor/Gutenberg sites)
     */
    private function get_theme_footer() {
        $footer_data = [
            'type' => 'theme',
            'widgets' => [],
            'copyright' => '',
        ];

        // Get footer widget areas
        $footer_sidebars = ['footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer'];
        foreach ($footer_sidebars as $sidebar) {
            if (is_active_sidebar($sidebar)) {
                ob_start();
                dynamic_sidebar($sidebar);
                $widgets_html = ob_get_clean();
                $footer_data['widgets'][$sidebar] = [
                    'html' => $widgets_html,
                ];
            }
        }

        // Get copyright text from theme mod or options
        if (function_exists('get_theme_mod')) {
            $footer_data['copyright'] = get_theme_mod('footer_copyright', '');
        }

        return !empty($footer_data['widgets']) || !empty($footer_data['copyright']) ? $footer_data : null;
    }
}

