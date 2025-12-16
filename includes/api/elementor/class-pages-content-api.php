<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Pages_Content_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        // Get page content by ID (auto-detect Elementor or Gutenberg)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content'],
            'permission_callback' => '__return_true',
            'show_in_rest' => true,
        ]);

        // Get page content by ID (alternative endpoint)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/content/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content'],
            'permission_callback' => '__return_true',
            'show_in_rest' => true,
        ]);

        // Get page content by slug (auto-detect Elementor or Gutenberg)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/slug/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content_by_slug'],
            'permission_callback' => '__return_true',
            'show_in_rest' => true,
        ]);

        // Get page content by slug (alternative endpoint)
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/content/slug/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_content_by_slug'],
            'permission_callback' => '__return_true',
            'show_in_rest' => true,
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/page-faqs/(?P<page_id>\d+)', [
            'methods'  => 'GET',
            'callback' => [$this,'restbridge_get_page_faqs'],
            'permission_callback' => '__return_true',
        ]);
    
    }

        function restbridge_get_page_faqs(WP_REST_Request $request) {

    $page_id = (int) $request->get_param('page_id');

    if (!$page_id || get_post_status($page_id) !== 'publish') {
        return new WP_Error('invalid_page', 'Invalid or unpublished page.', ['status' => 404]);
    }

    $post = get_post($page_id);
    if (!$post) {
        return new WP_Error('page_not_found', 'Page not found.', ['status' => 404]);
    }

    $content = apply_filters('the_content', $post->post_content);

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);

    $xpath = new DOMXPath($dom);

    /* STEP 1: EXTRACT FAQs (h3 only) */
    $faqs = [];

    $h3s = $xpath->query('//h3');

    foreach ($h3s as $heading) {

        $answerText = '';
        $toRemove   = [$heading];

        $next = $heading->nextSibling;

        while ($next) {

            if ($next->nodeName === 'h3') {
                break;
            }

            if (in_array($next->nodeName, ['p', 'div'])) {
                $answerText .= ' ' . trim($next->textContent);
                $toRemove[] = $next;
            }

            $next = $next->nextSibling;
        }

        if (trim($answerText) !== '') {
            $faqs[] = [
                'question' => trim($heading->textContent),
                'answer'   => trim(preg_replace('/\s+/', ' ', $answerText))
            ];

            // Remove FAQ question & answers from DOM
            foreach ($toRemove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /*  STEP 2: GET PAGE CONTENT AS STRUCTURED h2 + p */
    $content_blocks = [];

    $h2s = $xpath->query('//h2');

    foreach ($h2s as $h2) {

        $section = [
            'title'       => trim($h2->textContent),
            'description' => ''
        ];

        $descParts = [];
        $next = $h2->nextSibling;

        while ($next) {

            // Stop at next section
            if ($next->nodeName === 'h2') {
                break;
            }

            // Collect paragraph text only
            if ($next->nodeName === 'p') {
                $text = trim($next->textContent);
                if ($text !== '') {
                    $descParts[] = $text;
                }
            }

            $next = $next->nextSibling;
        }

        $section['description'] = trim(implode(' ', $descParts));
        $content_blocks[] = $section;
    }



        return rest_ensure_response([
            'page_id' => $page_id,
            'content' => $content_blocks, // FAQ text removed
            'count'   => count($faqs),
            'faqs'    => $faqs,
        ]);
    }
    


    /**
     * Get page content by ID (auto-detect Elementor or Gutenberg)
     */
    public function get_page_content(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $page = get_post($page_id);

        if (!$page) {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        return $this->parse_page_content($page);
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

        return $this->parse_page_content($page);
    }

    /**
     * Parse page content (auto-detect Elementor or Gutenberg)
     */
    private function parse_page_content($page) {
        // Check for Elementor first - always prioritize Elementor
        $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
        
        if (!empty($elementor_data)) {
            $response = $this->parse_elementor_content($page, $elementor_data);
            $response['editor'] = 'elementor';
            // Add plain text content
            $response['plain_text'] = $this->extract_plain_text_content($response);
            return $this->prepare_response_with_html($response);
        }

        // Check for Gutenberg - only if not Elementor
        $content = $page->post_content;
        if (!empty($content) && strpos($content, '<!-- wp:') !== false) {
            $response = $this->parse_gutenberg_content($page, $content);
            // Add plain text content
            $response['plain_text'] = $this->extract_plain_text_content($response);
            return $this->prepare_response_with_html($response);
        }

        // If content exists but no Elementor/Gutenberg, try to parse HTML into sections
        if (!empty($content)) {
            $response = $this->parse_html_content_into_sections($page, $content);
            $response['editor'] = 'html';
            $response['plain_text'] = $this->extract_plain_text_content($response);
            return $this->prepare_response_with_html($response);
        }

        return new WP_Error('no_content', 'This page does not have any content', ['status' => 404]);
    }

    /**
     * Prepare response with HTML preserved
     * This prevents WordPress from sanitizing HTML tags like span with inline styles
     */
    private function prepare_response_with_html($data) {
        // Return using rest_ensure_response which is the standard way
        // HTML sanitization in REST API is handled via schema validation
        // Since we're not defining a schema, the content should pass through as-is
        return rest_ensure_response($data);
    }

    /**
     * Parse HTML content into structured sections (fallback for non-Elementor pages)
     */
    private function parse_html_content_into_sections($page, $content) {
        $html = apply_filters('the_content', $content);
        $sections = [];
        
        // Split by major HTML elements to create sections
        // Look for headings, divs with classes, etc.
        preg_match_all('/(<h[1-6][^>]*>.*?<\/h[1-6]>(?:[^<]*(?:<(?![h1-6])[^>]*>[^<]*<\/[^>]+>)*[^<]*)*?)(?=<h[1-6]|$)/is', $html, $section_matches);
        
        $section_index = 0;
        foreach ($section_matches[1] as $section_html) {
            if (empty(trim($section_html))) {
                continue;
            }
            
            // Extract title from heading
            preg_match('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $section_html, $title_match);
            $title = $title_match ? wp_strip_all_tags($title_match[2]) : '';
            $title = preg_replace('/<br\s*\/?>/i', ' ', $title);
            $title = preg_replace('/\s+/', ' ', $title);
            $title = trim($title);
            
            // Extract description (content after heading)
            $description = $section_html;
            if ($title_match) {
                $description = substr($section_html, strpos($section_html, $title_match[0]) + strlen($title_match[0]));
            }
            $description = wp_strip_all_tags($description);
            $description = preg_replace('/<br\s*\/?>/i', ' ', $description);
            $description = preg_replace('/\s+/', ' ', $description);
            $description = trim($description);
            
            // Extract images
            $images = [];
            preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $section_html, $img_matches);
            if (!empty($img_matches[1])) {
                foreach ($img_matches[1] as $img_url) {
                    if (!empty($img_url) && strpos($img_url, 'data:image') === false) {
                        $images[] = ['url' => $img_url];
                    }
                }
            }
            
            // Extract links
            $links = [];
            preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $section_html, $link_matches, PREG_SET_ORDER);
            foreach ($link_matches as $link_match) {
                $link_text = wp_strip_all_tags($link_match[2]);
                $link_text = preg_replace('/<br\s*\/?>/i', ' ', $link_text);
                $link_text = preg_replace('/\s+/', ' ', $link_text);
                $link_text = trim($link_text);
                
                if (!empty($link_text) || !empty($link_match[1])) {
                    $links[] = [
                        'url' => $link_match[1],
                        'text' => $link_text,
                    ];
                }
            }
            
            if (!empty($title) || !empty($description) || !empty($images) || !empty($links)) {
                $widget_settings = [];
                
                // Add clean title
                if (!empty($title)) {
                    $widget_settings['title'] = $title;
                }
                
                // Add clean description
                if (!empty($description)) {
                    $widget_settings['description'] = $description;
                }
                
                // Add first image
                if (!empty($images) && isset($images[0]['url'])) {
                    $widget_settings['image'] = $this->build_image_object(null, $images[0]['url']);
                }
                
                // Add all images
                if (!empty($images)) {
                    $widget_settings['images'] = array_map(function($img) {
                        return $this->build_image_object(null, $img['url']);
                    }, $images);
                }
                
                // Add links
                if (!empty($links)) {
                    $widget_settings['links'] = $links;
                }
                
                $current_index = $section_index++;
                $section_entry = [
                    'index' => $current_index,
                    'section_number' => $current_index + 1, // Human-readable: 1, 2, 3, etc.
                    'position' => $this->get_section_position($current_index), // "first", "second", "third", etc.
                    'type' => 'section',
                    'id' => 'section-' . ($current_index + 1),
                    'settings' => [],
                    'columns' => [
                        [
                            'type' => 'column',
                            'settings' => [],
                            'widgets' => [
                                [
                                    'type' => 'content-block',
                                    'id' => 'widget-' . $section_index,
                                    'settings' => $widget_settings,
                                ],
                            ],
                            'total_widgets' => 1,
                        ],
                    ],
                    'total_columns' => 1,
                ];

                $this->transform_image_fields($section_entry);
                $sections[] = $section_entry;
            }
        }
        
        // If no sections found from headings, create one section with all content
        if (empty($sections)) {
            $section_entry = [
                'index' => 0,
                'section_number' => 1,
                'position' => 'first',
                'type' => 'section',
                'id' => 'section-1',
                'settings' => [],
                'columns' => [
                    [
                        'type' => 'column',
                        'settings' => [],
                        'widgets' => [
                            [
                                'type' => 'html-content',
                                'id' => 'widget-1',
                                'settings' => [
                                    'content' => $html,
                                    'text' => wp_strip_all_tags($html),
                                ],
                            ],
                        ],
                        'total_widgets' => 1,
                    ],
                ],
                'total_columns' => 1,
            ];

            $this->transform_image_fields($section_entry);
            $sections[] = $section_entry;
        }
        
        return [
            'page' => [
                'id' => $page->ID,
                'title' => get_the_title($page->ID),
                'slug' => $page->post_name,
                'permalink' => get_permalink($page->ID),
                'featured_image' => $this->build_image_object(get_post_thumbnail_id($page->ID)),
            ],
            'sections' => $sections,
            'total_sections' => count($sections),
        ];
    }

    /**
     * Get human-readable section position (first, second, third, etc.)
     */
    private function get_section_position($index) {
        $positions = [
            0 => 'first',
            1 => 'second',
            2 => 'third',
            3 => 'fourth',
            4 => 'fifth',
            5 => 'sixth',
            6 => 'seventh',
            7 => 'eighth',
            8 => 'ninth',
            9 => 'tenth',
        ];
        
        if (isset($positions[$index])) {
            return $positions[$index];
        }
        
        // For positions beyond 10, return "section-" + number
        return 'section-' . ($index + 1);
    }
}

