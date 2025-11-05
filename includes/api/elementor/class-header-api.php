<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Header_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        // Header API endpoints
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/header', [
            'methods' => 'GET',
            'callback' => [$this, 'get_header'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/header/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_header_by_id'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/headers', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_headers'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get active header (Elementor Theme Builder or Gutenberg template part)
     */
    public function get_header(WP_REST_Request $request) {
        // Try to get active Elementor header template
        $header = $this->get_active_elementor_template('header');
        
        if ($header) {
            // Enhance header with extracted metadata
            $header = $this->enhance_header_data($header);
            return rest_ensure_response($header);
        }

        // Try to get Gutenberg header template part
        $gutenberg_header = $this->get_gutenberg_template_part('header');
        if ($gutenberg_header) {
            // Enhance header with extracted metadata
            $gutenberg_header = $this->enhance_header_data($gutenberg_header);
            return rest_ensure_response($gutenberg_header);
        }

        // Try to get theme header widget area or menu
        $theme_header = $this->get_theme_header();
        if ($theme_header) {
            return rest_ensure_response($theme_header);
        }

        return new WP_Error('no_header', 'No header found', ['status' => 404]);
    }

    /**
     * Get header by ID
     */
    public function get_header_by_id(WP_REST_Request $request) {
        $header_id = (int) $request['id'];
        $header = get_post($header_id);

        if (!$header) {
            return new WP_Error('header_not_found', 'Header not found', ['status' => 404]);
        }

        // Check if it's an Elementor template
        $template_type = get_post_meta($header_id, '_elementor_template_type', true);
        if ($template_type === 'header') {
            $elementor_data = get_post_meta($header_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $response = $this->parse_elementor_content($header, $elementor_data);
                $response['type'] = 'elementor';
                $response['template_id'] = $header_id;
                // Enhance header with extracted metadata
                $response = $this->enhance_header_data($response);
                return rest_ensure_response($response);
            }
        }

        // Check if it's Gutenberg content
        $content = $header->post_content;
        if (!empty($content) && strpos($content, '<!-- wp:') !== false) {
            $response = $this->parse_gutenberg_content($header, $content);
            $response['type'] = 'gutenberg';
            $response['template_id'] = $header_id;
            // Enhance header with extracted metadata
            $response = $this->enhance_header_data($response);
            return rest_ensure_response($response);
        }

        return new WP_Error('invalid_header', 'Header template not found or invalid', ['status' => 404]);
    }

    /**
     * Get all headers
     */
    public function get_all_headers(WP_REST_Request $request) {
        $headers = [];

        // Get Elementor headers
        $elementor_headers = $this->get_elementor_templates('header');
        foreach ($elementor_headers as $header) {
            $headers[] = [
                'id' => $header->ID,
                'title' => get_the_title($header->ID),
                'slug' => $header->post_name,
                'type' => 'elementor',
                'status' => $header->post_status,
                'modified' => $header->post_modified,
            ];
        }

        // Get Gutenberg template parts (wp_template_part)
        $gutenberg_headers = $this->get_gutenberg_template_parts('header');
        foreach ($gutenberg_headers as $header) {
            $headers[] = [
                'id' => $header->ID,
                'title' => get_the_title($header->ID),
                'slug' => $header->post_name,
                'type' => 'gutenberg',
                'status' => $header->post_status,
                'modified' => $header->post_modified,
            ];
        }

        return rest_ensure_response([
            'headers' => $headers,
            'total' => count($headers),
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
     * Get theme header (fallback for non-Elementor/Gutenberg sites)
     */
    private function get_theme_header() {
        $header_data = [
            'type' => 'theme',
            'site_title' => '',
            'site_tagline' => '',
            'logos' => [],
            'menus' => [],
            'widgets' => [],
        ];

        // Get site title and tagline
        if (function_exists('get_bloginfo')) {
            $header_data['site_title'] = get_bloginfo('name');
            $header_data['site_tagline'] = get_bloginfo('description');
        }

        // Get logo from theme customizer
        if (function_exists('get_theme_mod')) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                $header_data['logos'][] = [
                    'id' => $custom_logo_id,
                    'url' => $logo_url,
                    'alt' => get_post_meta($custom_logo_id, '_wp_attachment_image_alt', true) ?: (function_exists('get_bloginfo') ? get_bloginfo('name') : ''),
                ];
            }
        }

        // Get menu locations
        $locations = get_nav_menu_locations();
        if (isset($locations['header']) || isset($locations['primary'])) {
            $location = isset($locations['header']) ? 'header' : 'primary';
            $menu_id = $locations[$location];
            $menu = wp_get_nav_menu_object($menu_id);
            
            if ($menu) {
                $menu_items = wp_get_nav_menu_items($menu_id);
                $header_data['menus'][$location] = array_map(function($item) {
                    return [
                        'id' => $item->ID,
                        'title' => $item->title,
                        'url' => $item->url,
                        'parent' => $item->menu_item_parent,
                        'target' => $item->target,
                    ];
                }, $menu_items ?: []);
            }
        }

        // Get header widget area
        if (is_active_sidebar('header') || is_active_sidebar('header-1')) {
            $sidebar = is_active_sidebar('header') ? 'header' : 'header-1';
            ob_start();
            dynamic_sidebar($sidebar);
            $widgets_html = ob_get_clean();
            $header_data['widgets']['html'] = $widgets_html;
        }

        return !empty($header_data['logos']) || !empty($header_data['menus']) || !empty($header_data['widgets']) ? $header_data : null;
    }

    /**
     * Enhance header data with extracted logos, icons, menus, etc.
     */
    private function enhance_header_data($header_data) {
        $enhanced = $header_data;
        $enhanced['extracted'] = [
            'site_title' => '',
            'site_tagline' => '',
            'logos' => [],
            'icons' => [],
            'menus' => [],
            'buttons' => [],
            'images' => [],
            'social_icons' => [],
        ];

        // Get site title from WordPress settings
        if (function_exists('get_bloginfo')) {
            $enhanced['extracted']['site_title'] = get_bloginfo('name');
            $enhanced['extracted']['site_tagline'] = get_bloginfo('description');
        }

        // Extract from Elementor structure
        if (isset($header_data['sections']) && is_array($header_data['sections'])) {
            foreach ($header_data['sections'] as $section) {
                $this->extract_header_elements_from_section($section, $enhanced['extracted']);
            }
        }

        // Extract from Gutenberg blocks
        if (isset($header_data['blocks']) && is_array($header_data['blocks'])) {
            foreach ($header_data['blocks'] as $block) {
                $this->extract_header_elements_from_block($block, $enhanced['extracted']);
            }
        }

        return $enhanced;
    }

    /**
     * Extract header elements from Elementor section
     */
    private function extract_header_elements_from_section($section, &$extracted) {
        // Check columns
        if (isset($section['columns']) && is_array($section['columns'])) {
            foreach ($section['columns'] as $column) {
                if (isset($column['widgets']) && is_array($column['widgets'])) {
                    foreach ($column['widgets'] as $widget) {
                        $this->extract_elements_from_widget($widget, $extracted);
                    }
                }
            }
        }

        // Check direct widgets
        if (isset($section['widgets']) && is_array($section['widgets'])) {
            foreach ($section['widgets'] as $widget) {
                $this->extract_elements_from_widget($widget, $extracted);
            }
        }
    }

    /**
     * Extract elements from widget
     */
    private function extract_elements_from_widget($widget, &$extracted) {
        if (!isset($widget['type']) || !isset($widget['settings'])) {
            return;
        }

        $widget_type = $widget['type'];
        $settings = $widget['settings'];

        // Extract logo
        if (in_array($widget_type, ['site-logo', 'logo', 'image'])) {
            $logo_url = '';
            $logo_id = null;

            if ($widget_type === 'site-logo' || $widget_type === 'logo') {
                $logo_id = isset($settings['logo_id']) ? $settings['logo_id'] : (isset($settings['image']['id']) ? $settings['image']['id'] : null);
                $logo_url = isset($settings['logo_url']) ? $settings['logo_url'] : (isset($settings['image_url']) ? $settings['image_url'] : '');
            } elseif ($widget_type === 'image') {
                $logo_id = isset($settings['image_id']) ? $settings['image_id'] : null;
                $logo_url = isset($settings['image_url']) ? $settings['image_url'] : '';
            }

            // Try theme logo if not found
            if (!$logo_id && function_exists('get_theme_mod')) {
                $custom_logo_id = get_theme_mod('custom_logo');
                if ($custom_logo_id) {
                    $logo_id = $custom_logo_id;
                    $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                }
            }

            if ($logo_url || $logo_id) {
                $extracted['logos'][] = [
                    'id' => $logo_id,
                    'url' => $logo_url,
                    'alt' => isset($settings['alt']) ? $settings['alt'] : '',
                    'widget_type' => $widget_type,
                ];
            }
        }

        // Extract icons
        if ($widget_type === 'icon-box' && isset($settings['icon'])) {
            $extracted['icons'][] = [
                'icon' => $settings['icon'],
                'title' => isset($settings['title']) ? $settings['title'] : '',
                'widget_type' => $widget_type,
            ];
        }

        // Extract social icons
        if ($widget_type === 'social-icons' && isset($settings['icons']) && is_array($settings['icons'])) {
            foreach ($settings['icons'] as $icon) {
                $extracted['social_icons'][] = [
                    'network' => isset($icon['network']) ? $icon['network'] : '',
                    'link' => isset($icon['link']) ? $icon['link'] : '',
                    'icon' => isset($icon['icon']) ? $icon['icon'] : '',
                ];
            }
        }

        // Extract icon list
        if ($widget_type === 'icon-list') {
            // Check if items are in settings directly or nested
            $items = isset($settings['items']) ? $settings['items'] : (isset($settings['icon_list']) ? $settings['icon_list'] : []);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $extracted['icons'][] = [
                        'icon' => isset($item['icon']) ? $item['icon'] : (isset($item['selected_icon']['value']) ? $item['selected_icon']['value'] : ''),
                        'text' => isset($item['text']) ? $item['text'] : '',
                        'widget_type' => $widget_type,
                    ];
                }
            }
        }

        // Extract menus
        if ($widget_type === 'nav-menu' && isset($settings['menu_items']) && is_array($settings['menu_items'])) {
            $extracted['menus'][] = [
                'menu_id' => isset($settings['menu_id']) ? $settings['menu_id'] : '',
                'items' => $settings['menu_items'],
            ];
        }

        // Extract site title from heading widgets (if it matches site title)
        if ($widget_type === 'heading' && isset($settings['title'])) {
            $heading_title = $settings['title'];
            // Check if heading matches site title
            if (function_exists('get_bloginfo')) {
                $site_title = get_bloginfo('name');
                if ($heading_title === $site_title || stripos($heading_title, $site_title) !== false) {
                    // If extracted site_title is empty or this is a better match, update it
                    if (empty($extracted['site_title']) || $heading_title === $site_title) {
                        $extracted['site_title'] = $heading_title;
                    }
                }
            }
        }

        // Extract buttons
        if ($widget_type === 'button' && isset($settings['text'])) {
            $extracted['buttons'][] = [
                'text' => $settings['text'],
                'link' => isset($settings['link']) ? $settings['link'] : '',
                'alignment' => isset($settings['alignment']) ? $settings['alignment'] : '',
            ];
        }

        // Extract images
        if (in_array($widget_type, ['image', 'image-box']) && isset($settings['image_url'])) {
            $extracted['images'][] = [
                'id' => isset($settings['image_id']) ? $settings['image_id'] : null,
                'url' => $settings['image_url'],
                'alt' => isset($settings['alt']) ? $settings['alt'] : '',
                'widget_type' => $widget_type,
            ];
        }

        // Recursively check nested elements
        if (isset($widget['elements']) && is_array($widget['elements'])) {
            foreach ($widget['elements'] as $nested_widget) {
                $this->extract_elements_from_widget($nested_widget, $extracted);
            }
        }
    }

    /**
     * Extract header elements from Gutenberg block
     */
    private function extract_header_elements_from_block($block, &$extracted) {
        if (!isset($block['type']) || !isset($block['content'])) {
            return;
        }

        $block_type = $block['type'];
        $content = $block['content'];

        // Extract logo/image
        if ($block_type === 'image' && isset($content['image_url'])) {
            $extracted['logos'][] = [
                'id' => isset($content['image_id']) ? $content['image_id'] : null,
                'url' => $content['image_url'],
                'alt' => isset($content['alt']) ? $content['alt'] : '',
                'block_type' => $block_type,
            ];
        }

        // Extract site title from heading blocks (if it matches site title)
        if ($block_type === 'heading' && isset($content['text'])) {
            $heading_text = $content['text'];
            // Check if heading matches site title
            if (function_exists('get_bloginfo')) {
                $site_title = get_bloginfo('name');
                if ($heading_text === $site_title || stripos($heading_text, $site_title) !== false) {
                    // If extracted site_title is empty or this is a better match, update it
                    if (empty($extracted['site_title']) || $heading_text === $site_title) {
                        $extracted['site_title'] = $heading_text;
                    }
                }
            }
        }

        // Extract buttons
        if ($block_type === 'button' && isset($content['text'])) {
            $extracted['buttons'][] = [
                'text' => $content['text'],
                'link' => isset($content['url']) ? $content['url'] : '',
            ];
        }

        // Extract images
        if ($block_type === 'image' && isset($content['image_url'])) {
            $extracted['images'][] = [
                'id' => isset($content['image_id']) ? $content['image_id'] : null,
                'url' => $content['image_url'],
                'alt' => isset($content['alt']) ? $content['alt'] : '',
            ];
        }

        // Extract from gallery
        if ($block_type === 'gallery' && isset($content['images']) && is_array($content['images'])) {
            foreach ($content['images'] as $img) {
                $extracted['images'][] = [
                    'id' => isset($img['id']) ? $img['id'] : null,
                    'url' => isset($img['url']) ? $img['url'] : '',
                    'alt' => isset($img['alt']) ? $img['alt'] : '',
                ];
            }
        }

        // Recursively check inner blocks
        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner_block) {
                $this->extract_header_elements_from_block($inner_block, $extracted);
            }
        }
    }
}

