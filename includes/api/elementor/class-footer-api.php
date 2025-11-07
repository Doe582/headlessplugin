<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Footer_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footer', [
            'methods'  => 'GET',
            'callback' => [$this, 'getFooter'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footer/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_footer_by_id'],
            'permission_callback' => '__return_true',
        ]);
    }
    public function getFooter($request) {

        $results = [
            'function' => 'getFooter',
            'html'     => '',
            'sections' => []
        ];
    
        // ✅ CASE 1: BLOCK THEMES (Twenty Twenty-Four / FSE)
        if (wp_is_block_theme()) {
    
            // Locate block footer template
            $footer_files = glob(get_theme_file_path('/parts/*footer*.html'));
    
            if ($footer_files && !empty($footer_files)) {
                $raw = file_get_contents($footer_files[0]);
                $footer_html = do_blocks($raw);
    
                $results['html'] = $footer_html;
                $results['sections'] = $this->format_footer_to_json_block($footer_html);
    
                return new WP_REST_Response($results, 200);
            }
        }
    
        //  CASE 2: CLASSIC THEMES (Astra, Kadence, etc.)
        ob_start();
        get_footer();
        $footer_html = ob_get_clean();
    
        //  Capture footer widget areas (footer-1, footer-2, ...)
        $widget_html = "";
        global $wp_registered_sidebars;
    
        foreach ($wp_registered_sidebars as $id => $sidebar) {
            if (strpos($id, 'footer') !== false) {
                ob_start();
                dynamic_sidebar($id);
                $content = ob_get_clean();
                if (trim($content) !== "") {
                    $widget_html .= '<div class="footer-widget-area" data-area="'.$id.'">'.$content.'</div>';
                }
            }
        }
    
        //  Combine widgets + footer bottom bar
        $footer_html = $widget_html . $footer_html;
    
        // Remove JS scripts
        $footer_html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $footer_html);
        $footer_html = trim($footer_html);
    
        $results['html'] = $footer_html;
        $results['sections'] = $this->format_footer_to_json_classic($footer_html);
    
        return new WP_REST_Response($results, 200);
    }
    
    
    /* ---------------------------------------------
       FORMATTER FOR BLOCK THEMES (FSE)
    ---------------------------------------------- */
    private function format_footer_to_json_block($html) {
    
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
    
        $sections = [];
    
        $groups = $xpath->query("//div[contains(@class,'wp-block-group')]");
    
        foreach ($groups as $group) {
            $headingNode = $xpath->query(".//h2|.//h3", $group)->item(0);
            if (!$headingNode) continue;
    
            $heading = trim($headingNode->textContent);
    
            $links = [];
            $linkNodes = $xpath->query(".//a", $group);
    
            foreach ($linkNodes as $a) {
                $text = trim($a->textContent);
                $url = $a->getAttribute('href');
    
                if ($text && $url) {
                    $links[] = ['title' => $text, 'url' => $url];
                }
            }
    
            if (!empty($links)) {
                $sections[] = [
                    'heading' => $heading,
                    'links'   => $links
                ];
            }
        }
    
        return $sections;
    }
    
    
    /* ---------------------------------------------
       ✅ FORMATTER FOR CLASSIC THEMES (Widgets)
    ---------------------------------------------- */
    private function format_footer_to_json_classic($html) {
    
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
    
        $sections = [];
    
        $widgetAreas = $xpath->query("//div[contains(@class,'footer-widget-area')]");
    
        foreach ($widgetAreas as $area) {
    
            $section = [
                'heading' => null,
                'links' => [],
                'contacts' => [],
                'social' => [],
                'newsletter_form' => null
            ];
    
            $headingNode = $xpath->query(".//h2[contains(@class,'widget-title')]", $area)->item(0);
            if ($headingNode) {
                $section['heading'] = trim($headingNode->textContent);
            }
    
            $linkNodes = $xpath->query(".//a", $area);
            foreach ($linkNodes as $a) {
                $text = trim($a->textContent);
                $url = $a->getAttribute('href');
                if (!$text || !$url) continue;
    
                if (strpos($a->getAttribute('class'), 'social') !== false ||
                    strpos($a->getAttribute('class'), 'fab') !== false) {
                    $section['social'][] = ['title' => $text, 'url' => $url];
                } else {
                    $section['links'][] = ['title' => $text, 'url' => $url];
                }
            }
    
            $contactNodes = $xpath->query(".//p", $area);
            foreach ($contactNodes as $p) {
                $text = trim(strip_tags($dom->saveHTML($p)));
                if ($text) {
                    $section['contacts'][] = $text;
                }
            }
    
            $formNode = $xpath->query(".//form", $area)->item(0);
            if ($formNode) {
                $section['newsletter_form'] = trim($dom->saveHTML($formNode));
            }
    
            $sections[] = $section;
        }
    
        return $sections;
    }
    
    
    function extract_clean_footer_html($html) {
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $html);
        $html = preg_replace('/<\/body>.*/is', '', $html);
    
        if (preg_match('/<footer.*?>(.*)<\/footer>/is', $html, $match)) {
            return trim($match[0]);
        }
    
        if (preg_match('/<div[^>]*id=("|\')?footer("|\')?[^>]*>(.*)<\/div>/is', $html, $match)) {
            return trim($match[0]);
        }
    
        return trim($html);
    }
    public function get_footer_by($request) {

        $results = [
            'function' => 'getFooter',
            'html'     => ''
        ];
    
        // ✅ CASE 1: Block Theme (Twenty Twenty-Four / etc.)
        if (wp_is_block_theme()) {
            $footer_files = glob(get_theme_file_path('/parts/*footer*.html'));
            if ($footer_files && !empty($footer_files)) {
                $raw = file_get_contents($footer_files[0]);
                $results['html'] = do_blocks($raw);
                return new WP_REST_Response($results, 200);
            }
        }
    
    
        // ✅ CASE 2: Classic Theme (Astra, Kadence, Elementor, Flatsome, etc.)
        ob_start();
        get_footer(); // This prints the below-footer bar also
        $footer_html = ob_get_clean();
    
    
        // ✅ Extract Footer Widget areas: footer-1, footer-2, footer-3...
        $sidebar_html = "";
    
        global $wp_registered_sidebars;
    
        foreach ($wp_registered_sidebars as $sidebar_id => $sidebar) {
            if (strpos($sidebar_id, 'footer') !== false) { // match footer-1, footer-2...
                ob_start();
                dynamic_sidebar($sidebar_id);
                $widget_content = ob_get_clean();
    
                if (trim($widget_content) !== "") {
                    $sidebar_html .= '<div class="footer-widget-area" data-area="' . $sidebar_id . '">' . $widget_content . '</div>';
                }
            }
        }
    
        // ✅ Combine widget columns + footer bar
        $clean_footer = $sidebar_html . $footer_html;
    
        // ✅ Strip unwanted scripts
        $clean_footer = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i', '', $clean_footer);
        $clean_footer = trim($clean_footer);
    
        $results['html'] = $clean_footer;
    
        $results['sections'] = $this->format_footer_to_json($results['html']);

return new WP_REST_Response($results, 200);
    }
    public function format_footer_to_json($footer_html) {

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($footer_html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
    
        $sections = [];
    
        // Loop each footer widget area
        $widgetAreas = $xpath->query("//div[contains(@class,'footer-widget-area')]");
    
        foreach ($widgetAreas as $area) {
    
            $section = [
                'heading' => null,
                'links' => [],
                'contacts' => [],
                'social' => [],
                'newsletter_form' => null
            ];
    
            // 1️⃣ Get Section Heading
            $headingNode = $xpath->query(".//h2[contains(@class,'widget-title')]", $area)->item(0);
            if ($headingNode) {
                $section['heading'] = trim($headingNode->textContent);
            }
    
            // 2️⃣ Extract Menu Links
            $linkNodes = $xpath->query(".//a", $area);
            foreach ($linkNodes as $a) {
                $text = trim($a->textContent);
                $url = $a->getAttribute('href');
    
                // Skip empty
                if (!$text || !$url) continue;
    
                // Detect social icons separately
                if (strpos($a->getAttribute('class'), 'social') !== false ||
                    strpos($a->getAttribute('class'), 'fab') !== false) {
                    $section['social'][] = [
                        'title' => $text,
                        'url' => $url
                    ];
                } else {
                    $section['links'][] = [
                        'title' => $text,
                        'url' => $url
                    ];
                }
            }
    
            // 3️⃣ Extract Contact Info (phone, email, address)
            $contactNodes = $xpath->query(".//p", $area);
            foreach ($contactNodes as $p) {
                $text = trim(strip_tags($dom->saveHTML($p)));
                if ($text) {
                    $section['contacts'][] = $text;
                }
            }
    
            // 4️⃣ Detect Newsletter Form (Mailchimp etc.)
            $formNode = $xpath->query(".//form", $area)->item(0);
            if ($formNode) {
                $section['newsletter_form'] = trim($dom->saveHTML($formNode));
            }
    
            $sections[] = $section;
        }
    
        return $sections;
    }
        

    
    


    public function getFooters($request) {

        $results = [
            'function'   => 'getFooters',
            'site_title' => get_bloginfo('name'),
            'logo'       => null,
            'footer'     => [],
            'text'       => ''
        ];
    
        // ✅ Logo
        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $results['logo'] = wp_get_attachment_image_url($logo_id, 'full');
        }
    
        // ✅ Path to footer.html in block theme
        $theme_dir = get_theme_root() . '/' . wp_get_theme()->get_stylesheet();
        $footer_file = $theme_dir . '/parts/footer.html';
    
        if (!file_exists($footer_file)) {
            return new WP_REST_Response($results, 200);
        }
    
        $footer_html = file_get_contents($footer_file);
        $blocks = parse_blocks($footer_html);
    
        // ✅ Collect only Navigation Block menu IDs used in footer
        $menu_ids = [];
    
        $scanBlocks = function($blocks) use (&$scanBlocks, &$menu_ids) {
            foreach ($blocks as $block) {
    
                if (
                    $block['blockName'] === 'core/navigation'
                    && !empty($block['attrs']['ref'])
                ) {
                    $menu_ids[] = $block['attrs']['ref']; // wp_navigation post ID
                }
    
                if (!empty($block['innerBlocks'])) {
                    $scanBlocks($block['innerBlocks']);
                }
            }
        };
    
        $scanBlocks($blocks);
    
        // ✅ Load ONLY the footer menus
        foreach ($menu_ids as $menu_id) {
    
            $menu_post = get_post($menu_id);
            if (!$menu_post) continue;
    
            $menu_blocks = parse_blocks($menu_post->post_content);
    
            $section = [
                'heading' => $menu_post->post_title,
                'links'   => []
            ];
    
            foreach ($menu_blocks as $block) {
                if ($block['blockName'] === 'core/navigation-link') {
                    $section['links'][] = [
                        'title' => $block['attrs']['label'] ?? '',
                        'url'   => $block['attrs']['url'] ?? ''
                    ];
                }
            }
    
            if (!empty($section['links'])) {
                $results['footer'][] = $section;
            }
        }
    
        // ✅ Footer text (small bottom text)
        ob_start();
        get_footer();
        $results['text'] = trim(wp_strip_all_tags(ob_get_clean(), true));
    
        return new WP_REST_Response($results, 200);
    }
    
    
    

    /**
     * MAIN API
     */
    public function get_footer_api(WP_REST_Request $request) {
        // 1) Elementor Footer (if exists)
        if (function_exists('elementor_theme_do_location')) {
            ob_start();
            $has_footer = elementor_theme_do_location('footer');
            $html = ob_get_clean();
            if ($has_footer && !empty(trim($html))) {
                $structured = $this->parse_widget_html($html);
                $this->extract_background_from_html($html, $structured);
                return rest_ensure_response([
                    'type' => 'elementor',
                    'sections' => [[
                        'widgets' => $structured,
                        'background' => $structured['background'] ?? [],
                    ]],
                    'plain_text' => $this->extract_plain_text_content(['sections' => [$structured]]),
                ]);
            }
        }

        // 2) Gutenberg footer template part (Block Themes)
        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('block_template_part')) {
            ob_start();
            block_template_part('footer');
            $html = ob_get_clean();
            if (!empty(trim($html))) {
                $structured = $this->parse_widget_html($html);
                $this->extract_background_from_html($html, $structured);
                return rest_ensure_response([
                    'type' => 'gutenberg',
                    'sections' => [[
                        'widgets' => $structured,
                        'background' => $structured['background'] ?? [],
                    ]],
                    'plain_text' => $this->extract_plain_text_content(['sections' => [$structured]]),
                ]);
            }
        }

        // 3) Classic Theme Footer (widget areas + footer.php)
        $theme_footer = $this->get_theme_footer();
        if ($theme_footer) {
            return rest_ensure_response($theme_footer);
        }

        return new WP_Error('no_footer_found', 'No footer detected', ['status' => 404]);
    }

    public function get_footer_by_id(WP_REST_Request $request) {
        $footer_id = (int) $request['id'];
        $footer = get_post($footer_id);

        if (!$footer) {
            return new WP_Error('footer_not_found', 'Footer not found', ['status' => 404]);
        }

        $template_type = get_post_meta($footer_id, '_elementor_template_type', true);
        if ($template_type === 'footer') {
            $elementor_data = get_post_meta($footer_id, '_elementor_data', true);
            if (!empty($elementor_data)) {
                $response = $this->parse_elementor_content($footer, $elementor_data);
                $response['type'] = 'elementor';
                $response['template_id'] = $footer_id;
                $response['plain_text'] = $this->extract_plain_text_content($response);
                return rest_ensure_response($response);
            }
        }

        $content = $footer->post_content;
        if (!empty($content)) {
            $response = $this->parse_gutenberg_content($footer, $content);
            if (!empty($response['sections']) || !empty($response['blocks'])) {
                $response['type'] = 'gutenberg';
                $response['template_id'] = $footer_id;
                $response['plain_text'] = $this->extract_plain_text_content($response);
                return rest_ensure_response($response);
            }
        }

        return new WP_Error('invalid_footer', 'Footer template not found or invalid', ['status' => 404]);
    }

    /**
     * Get theme footer with all widget areas organized by sections
     */
    private function get_theme_footer() {
        global $wp_registered_sidebars;
        $sections = [];
        $section_index = 0;
        $processed_sidebars = [];
        
        // Get all sidebars
        $all_sidebars = is_array($wp_registered_sidebars) ? array_keys($wp_registered_sidebars) : [];
        
        // Find footer sidebars (excluding footer-bottom, copyright)
        $footer_sidebars = [];
        foreach ($all_sidebars as $sidebar_id) {
            $sidebar_id_lower = strtolower($sidebar_id);
            if (stripos($sidebar_id_lower, 'footer') !== false && 
                stripos($sidebar_id_lower, 'bottom') === false &&
                stripos($sidebar_id_lower, 'copyright') === false &&
                is_active_sidebar($sidebar_id)) {
                $footer_sidebars[] = $sidebar_id;
                $processed_sidebars[$sidebar_id] = true;
            }
        }
        
        // Sort by number
        usort($footer_sidebars, function($a, $b) {
            preg_match('/(\d+)/', $a, $a_num);
            preg_match('/(\d+)/', $b, $b_num);
            return (isset($a_num[1]) ? (int)$a_num[1] : 999) <=> (isset($b_num[1]) ? (int)$b_num[1] : 999);
        });
        
        // Get footer background from theme options
        $footer_bg = $this->get_footer_background_from_theme_options();
        
        // Extract widgets from footer sidebars
        foreach ($footer_sidebars as $sidebar_id) {
            try {
                ob_start();
                $sidebar = isset($wp_registered_sidebars[$sidebar_id]) ? $wp_registered_sidebars[$sidebar_id] : null;
                
                if ($sidebar && isset($sidebar['before_widget'])) {
                    echo $sidebar['before_widget'];
                }
                
                dynamic_sidebar($sidebar_id);
                
                if ($sidebar && isset($sidebar['after_widget'])) {
                    echo $sidebar['after_widget'];
                }
                
                $full_sidebar_html = ob_get_clean();
                
                if (!empty(trim($full_sidebar_html))) {
                    $widget_data = $this->parse_widget_html($full_sidebar_html);
                    
                    // Add footer background if widget doesn't have one
                    if (empty($widget_data['background']) && !empty($footer_bg)) {
                        $widget_data['background'] = $footer_bg;
                    }
                    
                    // Also extract from HTML
                    $this->extract_background_from_html($full_sidebar_html, $widget_data);
                    
                    $sections[] = [
                        'index' => $section_index,
                        'section_number' => $section_index + 1,
                        'position' => $this->get_section_position($section_index),
                        'type' => 'footer-row',
                        'id' => $sidebar_id,
                        'settings' => [],
                        'columns' => [[
                            'type' => 'column',
                            'settings' => [],
                            'widgets' => [['type' => 'content-block', 'id' => $sidebar_id, 'settings' => $widget_data]],
                            'total_widgets' => 1,
                        ]],
                        'total_columns' => 1,
                        'background' => $widget_data['background'] ?? [],
                    ];
                    $section_index++;
                }
            } catch (Exception $e) {
                ob_end_clean();
            }
        }
        
        // Find footer bottom
        foreach ($all_sidebars as $sidebar_id) {
            if (isset($processed_sidebars[$sidebar_id])) continue;
            
            $sidebar_id_lower = strtolower($sidebar_id);
            if ((stripos($sidebar_id_lower, 'footer') !== false && 
                 (stripos($sidebar_id_lower, 'bottom') !== false || 
                  stripos($sidebar_id_lower, 'copyright') !== false ||
                  $sidebar_id_lower === 'footer')) ||
                stripos($sidebar_id_lower, 'footer-bottom') !== false) {
                if (is_active_sidebar($sidebar_id)) {
                    try {
                        ob_start();
                        $sidebar = isset($wp_registered_sidebars[$sidebar_id]) ? $wp_registered_sidebars[$sidebar_id] : null;
                        
                        if ($sidebar && isset($sidebar['before_widget'])) {
                            echo $sidebar['before_widget'];
                        }
                        
                        dynamic_sidebar($sidebar_id);
                        
                        if ($sidebar && isset($sidebar['after_widget'])) {
                            echo $sidebar['after_widget'];
                        }
                        
                        $footer_bottom_html = ob_get_clean();
                        
                        if (!empty(trim($footer_bottom_html))) {
                            $widget_data = $this->parse_widget_html($footer_bottom_html);
                            $this->extract_background_from_html($footer_bottom_html, $widget_data);
                            
                            $sections[] = [
                                'index' => $section_index,
                                'section_number' => $section_index + 1,
                                'position' => $this->get_section_position($section_index),
                                'type' => 'footer-bottom',
                                'id' => 'footer-bottom',
                                'settings' => [],
                                'columns' => [[
                                    'type' => 'column',
                                    'settings' => [],
                                    'widgets' => [['type' => 'content-block', 'id' => 'footer-bottom', 'settings' => $widget_data]],
                                    'total_widgets' => 1,
                                ]],
                                'total_columns' => 1,
                                'background' => $widget_data['background'] ?? [],
                            ];
                            $section_index++;
                            $processed_sidebars[$sidebar_id] = true;
                            break;
                        }
                    } catch (Exception $e) {
                        ob_end_clean();
                    }
                }
            }
        }
        
        // Get footer menu
        $footer_menu_items = [];
        $footer_menu_locations = get_nav_menu_locations();
        if (isset($footer_menu_locations['footer']) || isset($footer_menu_locations['footer-menu'])) {
            $menu_location = isset($footer_menu_locations['footer']) ? 'footer' : 'footer-menu';
            $menu_id = $footer_menu_locations[$menu_location];
            if ($menu_id) {
                $menu_items = wp_get_nav_menu_items($menu_id);
                if ($menu_items) {
                    foreach ($menu_items as $item) {
                        $footer_menu_items[] = ['title' => $item->title, 'url' => $item->url];
                    }
                }
            }
        }
        
        // Get copyright
        $copyright_text = '';
        $get_opt = function($key) {
            if (function_exists('cs_get_option')) {
                $value = cs_get_option($key);
                return ($value !== false && $value !== null) ? $value : '';
            }
            $value = get_theme_mod($key);
            return empty($value) ? get_option($key, '') : $value;
        };
        
        // Check theme mods
        foreach (get_theme_mods() ?: [] as $key => $value) {
            if ((stripos($key, 'copyright') !== false || (stripos($key, 'footer') !== false && stripos($key, 'text') !== false)) &&
                is_string($value) && !empty(trim($value))) {
                $copyright_text = $value;
                break;
            }
        }
        
        // Check common keys
        if (empty($copyright_text)) {
            foreach (['', 'kalles_', 'the4_', 'footer_'] as $prefix) {
                foreach (['footer_copyright', 'copyright_text', 'footer_text', 'copyright'] as $base) {
                    $key = ($prefix && $base === 'copyright') ? $prefix . 'footer_copyright' : $prefix . $base;
                    $value = $get_opt($key);
                    if (!empty($value) && is_string($value)) {
                        $copyright_text = $value;
                        break 2;
                    }
                }
            }
        }
        
        // Add copyright section if found
        if (!empty($copyright_text)) {
            $sections[] = [
                'index' => $section_index,
                'section_number' => $section_index + 1,
                'position' => $this->get_section_position($section_index),
                'type' => 'footer-copyright',
                'id' => 'footer-copyright',
                'settings' => [],
                'columns' => [[
                    'type' => 'column',
                    'settings' => [],
                    'widgets' => [['type' => 'content-block', 'id' => 'footer-copyright', 'settings' => ['copyright' => $copyright_text]]],
                    'total_widgets' => 1,
                ]],
                'total_columns' => 1,
            ];
        }
        
        if (empty($sections)) {
            return null;
        }
        
        return [
            'type' => 'theme',
            'sections' => $sections,
            'total_sections' => count($sections),
            'copyright' => $copyright_text,
            'plain_text' => $this->extract_plain_text_content(['sections' => $sections]),
        ];
    }

    /**
     * Get footer background from theme options (the4-theme-options footer tab)
     */
    private function get_footer_background_from_theme_options() {
        $background = [];
        
        // Helper to get theme options
        $get_opt = function($key, $default = '') {
            if (function_exists('cs_get_option')) {
                $value = cs_get_option($key);
                return ($value !== false && $value !== null) ? $value : $default;
            }
            $value = get_theme_mod($key);
            return empty($value) ? get_option($key, $default) : $value;
        };
        
        // Scan all theme mods for footer background
        $theme_mods = get_theme_mods();
        if (is_array($theme_mods)) {
            foreach ($theme_mods as $key => $value) {
                if (empty($value)) continue;
                
                $key_lower = strtolower($key);
                $is_footer_bg = (stripos($key_lower, 'footer') !== false) &&
                               (stripos($key_lower, 'background') !== false || stripos($key_lower, 'bg') !== false) &&
                               (stripos($key_lower, 'color') !== false || stripos($key_lower, 'image') !== false);
                
                if ($is_footer_bg) {
                    // Check for color
                    if (empty($background['color']) && stripos($key_lower, 'image') === false) {
                        if (is_string($value) && (preg_match('/^#?[0-9a-f]{3,6}$/i', $value) || stripos($value, 'rgb') !== false)) {
                            $background['color'] = $value;
                        } elseif (is_array($value) && isset($value['color'])) {
                            $background['color'] = $value['color'];
                        }
                    }
                    
                    // Check for image
                    if (empty($background['image_url']) && stripos($key_lower, 'image') !== false) {
                        if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                            $background['image_url'] = $value;
                        } elseif (is_numeric($value)) {
                            $img_url = wp_get_attachment_image_url($value, 'full');
                            if ($img_url) $background['image_url'] = $img_url;
                        } elseif (is_array($value)) {
                            if (isset($value['url'])) {
                                $background['image_url'] = $value['url'];
                            } elseif (isset($value['id']) && is_numeric($value['id'])) {
                                $img_url = wp_get_attachment_image_url($value['id'], 'full');
                                if ($img_url) $background['image_url'] = $img_url;
                            }
                        }
                    }
                }
            }
        }
        
        // Check common footer background keys (the4/kalles theme patterns)
        $keys_to_check = [
            'footer_background', 'footer_bg', 'footer_background_color', 'footer_bg_color',
            'footer_background_image', 'footer_bg_image', 'footer_top_background', 'footer_top_bg',
            'kalles_footer_background', 'kalles_footer_bg', 'the4_footer_background', 'the4_footer_bg',
            'footer_bg_color', 'footer_bg_image', 'footer_bg_image_id'
        ];
        
        foreach ($keys_to_check as $key) {
            if (!empty($background['color']) && !empty($background['image_url'])) {
                break;
            }
            
            $value = $get_opt($key);
            if (empty($value)) continue;
            
            if (stripos($key, 'image') !== false && empty($background['image_url'])) {
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $background['image_url'] = $value;
                } elseif (is_numeric($value)) {
                    $img_url = wp_get_attachment_image_url($value, 'full');
                    if ($img_url) $background['image_url'] = $img_url;
                } elseif (is_array($value)) {
                    if (isset($value['url'])) {
                        $background['image_url'] = $value['url'];
                    } elseif (isset($value['id']) && is_numeric($value['id'])) {
                        $img_url = wp_get_attachment_image_url($value['id'], 'full');
                        if ($img_url) $background['image_url'] = $img_url;
                    }
                }
            } elseif (stripos($key, 'color') !== false && empty($background['color'])) {
                if (is_string($value) && (preg_match('/^#?[0-9a-f]{3,6}$/i', $value) || stripos($value, 'rgb') !== false)) {
                    $background['color'] = $value;
                } elseif (is_array($value) && isset($value['color'])) {
                    $background['color'] = $value['color'];
                }
            }
        }
        
        return !empty($background) ? $background : [];
    }

    /**
     * Extract background from HTML → Mutates $target['background']
     */
    private function extract_background_from_html($html, &$target) {
        $background = [];
        
        // PRIORITY 1: Check for footer wrapper classes (like footer__top, footer-top, etc.)
        if (preg_match('/<div[^>]*class=["\'][^"\']*footer[_-]?(?:top|wrapper|container|main)[^"\']*["\'][^>]*>/i', $html, $footer_wrapper)) {
            $wrapper_tag = $footer_wrapper[0];
            
            // Extract from wrapper's inline style
            if (preg_match('/style=["\']([^"\']+)["\']/i', $wrapper_tag, $wrapper_style)) {
                $this->extract_background_from_style($wrapper_style[1], $background);
            }
            
            // Extract from wrapper's data attributes
            if (empty($background['color']) && preg_match('/data-bg-color=["\']([^"\']+)["\']/i', $wrapper_tag, $data_color)) {
                $background['color'] = trim($data_color[1]);
            }
            if (empty($background['image_url']) && preg_match('/data-bg-image=["\']([^"\']+)["\']/i', $wrapper_tag, $data_img)) {
                $bg_img_url = trim($data_img[1]);
                if (strpos($bg_img_url, 'http') !== 0 && strpos($bg_img_url, 'data:') !== 0) {
                    $bg_img_url = site_url($bg_img_url);
                }
                $background['image_url'] = $bg_img_url;
            }
        }
        
        // PRIORITY 2: Extract from all inline styles
        if (preg_match_all('/style=["\']([^"\']+)["\']/i', $html, $styles)) {
            foreach ($styles[1] as $style) {
                $this->extract_background_from_style($style, $background);
                if (!empty($background['color']) && !empty($background['image_url'])) break;
            }
        }
        
        // PRIORITY 3: Extract from data attributes anywhere
        if (empty($background['color']) && preg_match('/data-bg-color=["\']([^"\']+)["\']/i', $html, $m)) {
            $background['color'] = trim($m[1]);
        }
        if (empty($background['image_url']) && preg_match('/data-bg-image=["\']([^"\']+)["\']/i', $html, $m)) {
            $url = trim($m[1]);
            if (strpos($url, 'http') !== 0 && strpos($url, 'data:') !== 0) {
                $url = site_url($url);
            }
            $background['image_url'] = $url;
        }
        
        if (!empty($background)) {
            $target['background'] = $background;
        }
    }

    /**
     * Parse CSS background style properties
     */
    private function extract_background_from_style($style, &$background) {
        // Extract background-image first (higher priority)
        if (empty($background['image_url']) && preg_match('/background-image:\s*url\((["\']?)([^"\')]+)\1\)/i', $style, $m)) {
            $url = trim($m[2]);
            $url = preg_replace('/\s*!important\s*/i', '', $url);
            if (!empty($url) && strpos($url, 'data:') !== 0) {
                if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                    $url = site_url($url);
                }
                $background['image_url'] = $url;
            }
        }
        
        // Extract background-color
        if (empty($background['color']) && preg_match('/background-color:\s*([^;!]+)/i', $style, $m)) {
            $color = trim(str_replace('!important', '', $m[1]));
            if (!empty($color) && !in_array(strtolower($color), ['transparent', 'none', 'inherit', 'initial', 'unset', 'rgba(0,0,0,0)', 'rgba(0, 0, 0, 0)'])) {
                $background['color'] = $color;
            }
        }
        
        // Extract background (shorthand)
        if (preg_match('/background:\s*([^;]+)/i', $style, $m)) {
            $val = preg_replace('/\s*!important\s*/i', '', trim($m[1]));
            
            // Check for image URL first
            if (empty($background['image_url']) && preg_match('/url\((["\']?)([^"\')]+)\1\)/i', $val, $u)) {
                $url = trim($u[2]);
                if (!empty($url) && strpos($url, 'data:') !== 0) {
                    if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                        $url = site_url($url);
                    }
                    $background['image_url'] = $url;
                }
            }
            
            // Check for color
            if (empty($background['color']) && preg_match('/(#[0-9a-f]{3,6}|rgba?\([^)]*\)|hsl\([^)]*\)|hsla\([^)]*\)|[a-z]+)/i', $val, $c)) {
                $color = trim($c[1]);
                if (!in_array(strtolower($color), ['transparent', 'none', 'inherit', 'initial', 'unset'])) {
                    $background['color'] = $color;
                }
            }
        }
    }

    private function parse_widget_html($html) {
        if (empty($html)) {
            return ['title' => '', 'menu_items' => [], 'images' => [], 'links' => [], 'text_content' => [], 'form_fields' => [], 'icons' => [], 'background' => []];
        }

        $structured = ['title' => '', 'menu_items' => [], 'images' => [], 'links' => [], 'text_content' => [], 'form_fields' => [], 'icons' => [], 'background' => []];
        $seen_texts = [];
        $seen_links = [];
        $seen_icons = [];
        $seen_images = [];
        $seen_form_fields = [];

        // Helper to clean and add text
        $add_text = function($text) use (&$structured, &$seen_texts) {
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', trim($text));
            if (!empty($text) && strlen($text) > 3 && !isset($seen_texts[strtolower($text)])) {
                $structured['text_content'][] = $text;
                $seen_texts[strtolower($text)] = true;
            }
        };

        // Extract title
        if (preg_match_all('/<h[1-6][^>]*class=["\']widget-title[^"\']*["\'][^>]*>(.*?)<\/h[1-6]>/is', $html, $matches)) {
            $title = strip_tags($matches[1][0]);
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $structured['title'] = trim($title);
        } elseif (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $matches)) {
            $title = strip_tags($matches[1][0]);
            $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $structured['title'] = trim($title);
        }

        // Extract menu items
        if (preg_match_all('/<li[^>]*class=["\'][^"\']*menu-item[^"\']*["\'][^>]*>.*?<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = trim($match[1]);
                $text = strip_tags($match[2]);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!empty(trim($text))) {
                    if (strpos($url, 'http') !== 0 && strpos($url, 'mailto:') !== 0 && strpos($url, 'tel:') !== 0) {
                        $url = site_url($url);
                    }
                    $structured['menu_items'][] = ['title' => trim($text), 'url' => $url];
                }
            }
        }

        // Extract images
        if (preg_match_all('/<img[^>]+>/i', $html, $matches)) {
            foreach ($matches[0] as $img_tag) {
                $img_url = '';
                $img_alt = '';
                
                if (preg_match('/alt=["\']([^"\']*)["\']/i', $img_tag, $alt_match)) {
                    $img_alt = html_entity_decode(trim($alt_match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                
                if (preg_match('/(?:srcset|data-srcset)=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
                    if (preg_match('/^([^\s,]+)/', $srcset_match[1], $url_match)) {
                        $img_url = trim($url_match[1]);
                    }
                } elseif (preg_match('/(?:src|data-src)=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                    $img_url = trim($src_match[1]);
                }
                
                if (!empty($img_url) && strpos($img_url, 'data:') !== 0) {
                    if (strpos($img_url, 'http') !== 0) {
                        $img_url = site_url($img_url);
                    }
                    if (!isset($seen_images[$img_url])) {
                        $structured['images'][] = ['url' => $img_url, 'alt' => $img_alt];
                        $seen_images[$img_url] = true;
                    }
                }
            }
        }

        // Extract links (non-menu)
        if (preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = trim($match[1]);
                $text = strip_tags($match[2]);
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = trim($text);
                
                if (empty($text) || preg_match('/^[\s]*$/', $text)) {
                    continue;
                }
                
                // Check if already in menu_items
                $is_menu = false;
                foreach ($structured['menu_items'] as $menu_item) {
                    if ($menu_item['title'] === $text && $menu_item['url'] === $url) {
                        $is_menu = true;
                        break;
                    }
                }
                
                if (!$is_menu) {
                    if (strpos($url, 'http') !== 0 && strpos($url, 'mailto:') !== 0 && strpos($url, 'tel:') !== 0) {
                        $url = site_url($url);
                    }
                    $link_key = strtolower($text . '|' . $url);
                    if (!isset($seen_links[$link_key])) {
                        $structured['links'][] = ['title' => $text, 'url' => $url];
                        $seen_links[$link_key] = true;
                    }
                }
            }
        }

        // Extract forms
        if (preg_match('/<form[^>]*action=["\']([^"\']+)["\']/i', $html, $match)) {
            $structured['form_action'] = $match[1];
        }
        
        if (preg_match_all('/<input[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $input_tag) {
                $input_data = [];
                if (preg_match('/type=["\']([^"\']+)["\']/i', $input_tag, $m)) $input_data['type'] = $m[1];
                if (preg_match('/name=["\']([^"\']+)["\']/i', $input_tag, $m)) $input_data['name'] = $m[1];
                if (preg_match('/placeholder=["\']([^"\']+)["\']/i', $input_tag, $m)) $input_data['placeholder'] = $m[1];
                if (preg_match('/value=["\']([^"\']+)["\']/i', $input_tag, $m)) $input_data['value'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/id=["\']([^"\']+)["\']/i', $input_tag, $m)) $input_data['id'] = $m[1];
                if (!empty($input_data)) {
                    $field_key = md5(serialize($input_data));
                    if (!isset($seen_form_fields[$field_key])) {
                        $structured['form_fields'][] = $input_data;
                        $seen_form_fields[$field_key] = true;
                    }
                }
            }
        }
        
        if (preg_match_all('/<textarea[^>]*name=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $name) {
                $field_key = 'textarea|' . $name;
                if (!isset($seen_form_fields[$field_key])) {
                    $structured['form_fields'][] = ['type' => 'textarea', 'name' => $name];
                    $seen_form_fields[$field_key] = true;
                }
            }
        }
        
        if (preg_match_all('/<select[^>]*name=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $name) {
                $field_key = 'select|' . $name;
                if (!isset($seen_form_fields[$field_key])) {
                    $structured['form_fields'][] = ['type' => 'select', 'name' => $name];
                    $seen_form_fields[$field_key] = true;
                }
            }
        }
        
        if (preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $html, $matches)) {
            foreach ($matches[1] as $label_text) {
                $label = strip_tags($label_text);
                $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim($label);
                if (!empty($label) && strlen($label) > 3 && 
                    stripos($label, 'honeypot') === false && stripos($label, 'human') === false) {
                    $field_key = 'label|' . strtolower($label);
                    if (!isset($seen_form_fields[$field_key])) {
                        $structured['form_fields'][] = ['type' => 'label', 'value' => $label];
                        $seen_form_fields[$field_key] = true;
                    }
                }
            }
        }
        
        if (preg_match_all('/<button[^>]*type=["\'](submit|button)["\'][^>]*>(.*?)<\/button>/is', $html, $matches)) {
            foreach ($matches[2] as $button_content) {
                $btn_text = strip_tags($button_content);
                $btn_text = html_entity_decode($btn_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $btn_text = trim($btn_text);
                if (!empty($btn_text)) {
                    $field_key = 'button|' . strtolower($btn_text);
                    if (!isset($seen_form_fields[$field_key])) {
                        $structured['form_fields'][] = ['type' => 'button', 'value' => $btn_text];
                        $seen_form_fields[$field_key] = true;
                    }
                }
            }
        }

        // Extract all text content
        $clean_html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $clean_html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $clean_html);
        
        // Extract from custom widgets first
        if (preg_match_all('/<div[^>]*class=["\'][^"\']*(?:textwidget|custom-html-widget)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $clean_html, $matches)) {
            foreach ($matches[1] as $custom_html) {
                if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $custom_html, $p_matches)) {
                    foreach ($p_matches[1] as $p_text) {
                        $p_text = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $p_text);
                        $p_text = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $p_text);
                        $p_text = preg_replace('/<span[^>]*>(.*?)<\/span>/is', '$1', $p_text);
                        $add_text($p_text);
                    }
                }
            }
        }
        
        // Extract from all other paragraphs
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $clean_html, $matches)) {
            foreach ($matches[1] as $p_text) {
                $p_text = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $p_text);
                $p_text = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $p_text);
                $p_text = preg_replace('/<span[^>]*>(.*?)<\/span>/is', '$1', $p_text);
                $add_text($p_text);
            }
        }
        
        // Extract icons
        if (preg_match_all('/class=["\']([^"\']*(?:icon|fa|fab)[^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $classes) {
                foreach (explode(' ', $classes) as $class) {
                    $class = trim($class);
                    if (!empty($class) && (strpos($class, 'icon') !== false || strpos($class, 'fa-') !== false)) {
                        if (!isset($seen_icons[$class])) {
                            $structured['icons'][] = $class;
                            $seen_icons[$class] = true;
                        }
                    }
                }
            }
        }

        // Remove empty arrays (but keep background)
        foreach ($structured as $key => $value) {
            if (is_array($value) && empty($value) && $key !== 'background') {
                unset($structured[$key]);
            }
        }
        
        // Clean background if truly empty
        if (isset($structured['background']) && empty($structured['background'])) {
            unset($structured['background']);
        }

        return $structured;
    }
}
