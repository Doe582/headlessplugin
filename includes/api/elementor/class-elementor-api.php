<?php

class RESTBridge_Elementor_API {

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
     * Parse Elementor content and extract structured data
     */
    private function parse_elementor_content($page, $elementor_data) {
        // Elementor data is stored as JSON string
        if (is_string($elementor_data)) {
            $elementor_data = json_decode($elementor_data, true);
        }

        if (!is_array($elementor_data)) {
            return [
                'page' => [
                    'id' => $page->ID,
                    'title' => get_the_title($page->ID),
                    'slug' => $page->post_name,
                    'permalink' => get_permalink($page->ID),
                ],
                'sections' => [],
                'error' => 'Invalid Elementor data format'
            ];
        }

        $sections = [];
        
        foreach ($elementor_data as $section_index => $section) {
            $parsed_section = $this->parse_section($section, $section_index);
            if ($parsed_section) {
                $sections[] = $parsed_section;
            }
        }

        return [
            'page' => [
                'id' => $page->ID,
                'title' => get_the_title($page->ID),
                'slug' => $page->post_name,
                'permalink' => get_permalink($page->ID),
                'featured_image' => get_the_post_thumbnail_url($page->ID, 'full'),
            ],
            'sections' => $sections,
            'total_sections' => count($sections),
        ];
    }

    /**
     * Parse a single section/container
     */
    private function parse_section($section, $index) {
        if (!isset($section['elements']) || !is_array($section['elements'])) {
            return null;
        }

        $el_type = isset($section['elType']) ? $section['elType'] : 'section';
        $section_settings = isset($section['settings']) ? $section['settings'] : [];
        
        $columns = [];
        $widgets = [];

        // Handle both old (section) and new (container) Elementor structures
        foreach ($section['elements'] as $element) {
            $element_type = isset($element['elType']) ? $element['elType'] : '';
            
            if ($element_type === 'column') {
                // Old structure: section → column → widget
                $parsed_column = $this->parse_column($element);
                if ($parsed_column) {
                    $columns[] = $parsed_column;
                }
            } elseif ($element_type === 'widget') {
                // New structure: container → widget (direct)
                $parsed_widget = $this->parse_widget($element);
                if ($parsed_widget) {
                    $widgets[] = $parsed_widget;
                }
            } elseif ($element_type === 'container') {
                // Nested container: recursively extract widgets from nested containers
                if (isset($element['elements']) && is_array($element['elements'])) {
                    foreach ($element['elements'] as $nested_element) {
                        $nested_type = isset($nested_element['elType']) ? $nested_element['elType'] : '';
                        
                        if ($nested_type === 'widget') {
                            // Widget directly in nested container
                            $parsed_widget = $this->parse_widget($nested_element);
                            if ($parsed_widget) {
                                $widgets[] = $parsed_widget;
                            }
                        } elseif ($nested_type === 'container') {
                            // Deeply nested container - recursively parse
                            $deep_nested = $this->parse_section($nested_element, 0);
                            if ($deep_nested) {
                                if (!empty($deep_nested['columns'])) {
                                    $columns = array_merge($columns, $deep_nested['columns']);
                                }
                                if (!empty($deep_nested['widgets'])) {
                                    $widgets = array_merge($widgets, $deep_nested['widgets']);
                                }
                            }
                        } elseif ($nested_type === 'column') {
                            // Column in nested container
                            $parsed_column = $this->parse_column($nested_element);
                            if ($parsed_column) {
                                $columns[] = $parsed_column;
                            }
                        }
                    }
                }
            }
        }

        $result = [
            'index' => $index,
            'type' => $el_type,
            'id' => isset($section['id']) ? $section['id'] : '',
            'settings' => $this->clean_settings($section_settings),
        ];

        // If we have columns, use them; otherwise use direct widgets
        if (!empty($columns)) {
            $result['columns'] = $columns;
            $result['total_columns'] = count($columns);
        } elseif (!empty($widgets)) {
            $result['widgets'] = $widgets;
            $result['total_widgets'] = count($widgets);
        } else {
            $result['columns'] = [];
            $result['total_columns'] = 0;
        }

        return $result;
    }

    /**
     * Parse a column and extract widgets
     */
    private function parse_column($column) {
        if (!isset($column['elements']) || !is_array($column['elements'])) {
            return null;
        }

        $widgets = [];

        foreach ($column['elements'] as $widget) {
            $parsed_widget = $this->parse_widget($widget);
            if ($parsed_widget) {
                $widgets[] = $parsed_widget;
            }
        }

        $column_settings = isset($column['settings']) ? $column['settings'] : [];

        return [
            'type' => isset($column['elType']) ? $column['elType'] : 'column',
            'settings' => $this->clean_settings($column_settings),
            'widgets' => $widgets,
            'total_widgets' => count($widgets),
        ];
    }

    /**
     * Parse a widget and extract its content
     */
    private function parse_widget($widget) {
        $widget_type = isset($widget['widgetType']) ? $widget['widgetType'] : '';
        $settings = isset($widget['settings']) ? $widget['settings'] : [];

        $widget_data = [
            'type' => $widget_type,
            'id' => isset($widget['id']) ? $widget['id'] : '',
            'settings' => $this->extract_widget_content($widget_type, $settings),
        ];

        // Handle nested elements (like tabs, accordions, etc.)
        if (isset($widget['elements']) && is_array($widget['elements'])) {
            $widget_data['elements'] = [];
            foreach ($widget['elements'] as $nested_element) {
                $parsed = $this->parse_widget($nested_element);
                if ($parsed) {
                    $widget_data['elements'][] = $parsed;
                }
            }
        }

        return $widget_data;
    }

    /**
     * Extract content from widget settings based on widget type
     */
    private function extract_widget_content($widget_type, $settings) {
        $content = [];

        switch ($widget_type) {
            case 'heading':
                $content = [
                    'title' => isset($settings['title']) ? $settings['title'] : '',
                    'size' => isset($settings['size']) ? $settings['size'] : 'default',
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'text-editor':
                $content = [
                    'editor' => isset($settings['editor']) ? $settings['editor'] : '',
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'button':
                $content = [
                    'text' => isset($settings['text']) ? $settings['text'] : '',
                    'link' => isset($settings['link']['url']) ? $settings['link']['url'] : '',
                    'link_text' => isset($settings['link']['url']) ? $settings['link']['url'] : '',
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'image':
                $image_id = isset($settings['image']['id']) ? $settings['image']['id'] : (isset($settings['image']) && is_numeric($settings['image']) ? $settings['image'] : null);
                $image_url = isset($settings['image']['url']) ? $settings['image']['url'] : '';
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                $content = [
                    'image_id' => $image_id,
                    'image_url' => $image_url,
                    'alt' => isset($settings['image']['alt']) ? $settings['image']['alt'] : '',
                    'caption' => isset($settings['caption']) ? $settings['caption'] : '',
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'image-box':
                $image_id = isset($settings['image']['id']) ? $settings['image']['id'] : null;
                $image_url = isset($settings['image']['url']) ? $settings['image']['url'] : '';
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                $content = [
                    'title' => isset($settings['title_text']) ? $settings['title_text'] : '',
                    'description' => isset($settings['description_text']) ? $settings['description_text'] : '',
                    'image_id' => $image_id,
                    'image_url' => $image_url,
                ];
                break;

            case 'icon-box':
                $content = [
                    'title' => isset($settings['title_text']) ? $settings['title_text'] : '',
                    'description' => isset($settings['description_text']) ? $settings['description_text'] : '',
                    'icon' => isset($settings['selected_icon']['value']) ? $settings['selected_icon']['value'] : '',
                ];
                break;

            case 'video':
                $content = [
                    'video_url' => isset($settings['video_link']) ? $settings['video_link'] : '',
                    'poster' => isset($settings['poster']['url']) ? $settings['poster']['url'] : '',
                    'autoplay' => isset($settings['autoplay']) ? $settings['autoplay'] : false,
                ];
                break;

            case 'html':
                $content = [
                    'html' => isset($settings['html']) ? $settings['html'] : '',
                ];
                break;

            case 'shortcode':
                $content = [
                    'shortcode' => isset($settings['shortcode']) ? $settings['shortcode'] : '',
                ];
                break;

            case 'spacer':
                $content = [
                    'space' => isset($settings['space']['size']) ? $settings['space']['size'] : '',
                ];
                break;

            case 'divider':
                $content = [
                    'style' => isset($settings['style']) ? $settings['style'] : '',
                    'weight' => isset($settings['weight']['size']) ? $settings['weight']['size'] : '',
                ];
                break;

            case 'accordion':
                $items = [];
                if (isset($settings['tabs']) && is_array($settings['tabs'])) {
                    foreach ($settings['tabs'] as $tab) {
                        $items[] = [
                            'title' => isset($tab['tab_title']) ? $tab['tab_title'] : '',
                            'content' => isset($tab['tab_content']) ? $tab['tab_content'] : '',
                        ];
                    }
                }
                $content = ['items' => $items];
                break;

            case 'tabs':
                $tabs = [];
                if (isset($settings['tabs']) && is_array($settings['tabs'])) {
                    foreach ($settings['tabs'] as $tab) {
                        $tabs[] = [
                            'title' => isset($tab['tab_title']) ? $tab['tab_title'] : '',
                            'content' => isset($tab['tab_content']) ? $tab['tab_content'] : '',
                        ];
                    }
                }
                $content = ['tabs' => $tabs];
                break;

            case 'testimonial':
                $content = [
                    'content' => isset($settings['content']) ? $settings['content'] : '',
                    'name' => isset($settings['name']) ? $settings['name'] : '',
                    'job' => isset($settings['job']) ? $settings['job'] : '',
                    'image' => isset($settings['image']['url']) ? $settings['image']['url'] : '',
                ];
                break;

            case 'icon-list':
                $items = [];
                if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
                    foreach ($settings['icon_list'] as $item) {
                        $items[] = [
                            'text' => isset($item['text']) ? $item['text'] : '',
                            'icon' => isset($item['selected_icon']['value']) ? $item['selected_icon']['value'] : '',
                        ];
                    }
                }
                $content = ['items' => $items];
                break;

            case 'social-icons':
                $icons = [];
                if (isset($settings['social_icon_list']) && is_array($settings['social_icon_list'])) {
                    foreach ($settings['social_icon_list'] as $icon) {
                        $icons[] = [
                            'network' => isset($icon['social']) ? $icon['social'] : '',
                            'link' => isset($icon['link']['url']) ? $icon['link']['url'] : '',
                            'icon' => isset($icon['selected_icon']['value']) ? $icon['selected_icon']['value'] : '',
                        ];
                    }
                }
                $content = ['icons' => $icons];
                break;

            case 'site-logo':
            case 'logo':
                // Site logo widget
                $image_id = isset($settings['image']['id']) ? $settings['image']['id'] : (isset($settings['image']) && is_numeric($settings['image']) ? $settings['image'] : null);
                $image_url = isset($settings['image']['url']) ? $settings['image']['url'] : '';
                
                // Try to get logo from customizer if not set
                if (!$image_id && function_exists('get_theme_mod')) {
                    $custom_logo_id = get_theme_mod('custom_logo');
                    if ($custom_logo_id) {
                        $image_id = $custom_logo_id;
                        $image_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                    }
                }
                
                if ($image_id && !$image_url) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                $content = [
                    'logo_id' => $image_id,
                    'logo_url' => $image_url,
                    'alt' => isset($settings['image']['alt']) ? $settings['image']['alt'] : (function_exists('get_bloginfo') ? get_bloginfo('name') : ''),
                    'link' => isset($settings['link']['url']) ? $settings['link']['url'] : home_url('/'),
                    'link_target' => isset($settings['link']['is_external']) ? ($settings['link']['is_external'] ? '_blank' : '_self') : '_self',
                ];
                break;

            case 'nav-menu':
            case 'wp-widget-nav_menu':
                // Navigation menu widget
                $menu_id = isset($settings['menu']) ? $settings['menu'] : '';
                $menu_items = [];
                
                if ($menu_id) {
                    $items = wp_get_nav_menu_items($menu_id);
                    if ($items) {
                        foreach ($items as $item) {
                            $menu_items[] = [
                                'id' => $item->ID,
                                'title' => $item->title,
                                'url' => $item->url,
                                'parent' => $item->menu_item_parent,
                                'target' => $item->target,
                                'description' => $item->description,
                            ];
                        }
                    }
                }
                
                $content = [
                    'menu_id' => $menu_id,
                    'menu_items' => $menu_items,
                ];
                break;

            case 'search':
            case 'search-form':
                // Search widget
                $content = [
                    'placeholder' => isset($settings['placeholder']) ? $settings['placeholder'] : 'Search...',
                    'button_text' => isset($settings['button_text']) ? $settings['button_text'] : 'Search',
                ];
                break;

            default:
                // For any other widget type, return all settings
                $content = $this->clean_settings($settings);
                break;
        }

        return $content;
    }

    /**
     * Clean settings array - remove internal Elementor keys and format values
     */
    private function clean_settings($settings) {
        if (!is_array($settings)) {
            return [];
        }

        $cleaned = [];
        $skip_keys = ['__globals__', '_element_id'];

        foreach ($settings as $key => $value) {
            if (in_array($key, $skip_keys)) {
                continue;
            }

            // Handle nested arrays
            if (is_array($value)) {
                // If it's an image/icon object, extract URL
                if (isset($value['url'])) {
                    $cleaned[$key] = $value['url'];
                } elseif (isset($value['id'])) {
                    $cleaned[$key] = [
                        'id' => $value['id'],
                        'url' => isset($value['url']) ? $value['url'] : wp_get_attachment_url($value['id']),
                    ];
                } else {
                    $cleaned[$key] = $this->clean_settings($value);
                }
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Parse Gutenberg content and extract structured data
     */
    private function parse_gutenberg_content($page, $content) {
        if (!function_exists('parse_blocks')) {
            return [
                'page' => [
                    'id' => $page->ID,
                    'title' => get_the_title($page->ID),
                    'slug' => $page->post_name,
                    'permalink' => get_permalink($page->ID),
                ],
                'blocks' => [],
                'error' => 'Gutenberg parse_blocks function not available'
            ];
        }

        $blocks = parse_blocks($content);
        $parsed_blocks = [];

        foreach ($blocks as $block_index => $block) {
            // Skip empty blocks
            if (empty($block['blockName']) && empty(trim($block['innerHTML']))) {
                continue;
            }

            $parsed_block = $this->parse_gutenberg_block($block, $block_index);
            if ($parsed_block) {
                $parsed_blocks[] = $parsed_block;
            }
        }

        return [
            'page' => [
                'id' => $page->ID,
                'title' => get_the_title($page->ID),
                'slug' => $page->post_name,
                'permalink' => get_permalink($page->ID),
                'featured_image' => get_the_post_thumbnail_url($page->ID, 'full'),
                'editor' => 'gutenberg',
            ],
            'blocks' => $parsed_blocks,
            'total_blocks' => count($parsed_blocks),
        ];
    }

    /**
     * Parse a single Gutenberg block
     */
    private function parse_gutenberg_block($block, $index) {
        $block_name = $block['blockName'] ?? '';
        $attrs = $block['attrs'] ?? [];
        $inner_html = $block['innerHTML'] ?? '';
        $inner_blocks = $block['innerBlocks'] ?? [];

        // Extract block type (e.g., "core/heading" -> "heading")
        $block_type = '';
        if (!empty($block_name)) {
            $parts = explode('/', $block_name);
            $block_type = end($parts);
        }

        $block_data = [
            'index' => $index,
            'type' => $block_type,
            'block_name' => $block_name,
            'content' => $this->extract_gutenberg_block_content($block_type, $attrs, $inner_html, $block),
            'attributes' => $this->clean_settings($attrs),
        ];

        // Parse nested blocks
        if (!empty($inner_blocks)) {
            $block_data['blocks'] = [];
            foreach ($inner_blocks as $inner_index => $inner_block) {
                $parsed_inner = $this->parse_gutenberg_block($inner_block, $inner_index);
                if ($parsed_inner) {
                    $block_data['blocks'][] = $parsed_inner;
                }
            }
            $block_data['total_blocks'] = count($block_data['blocks']);
        }

        return $block_data;
    }

    /**
     * Extract content from Gutenberg block based on block type
     */
    private function extract_gutenberg_block_content($block_type, $attrs, $inner_html, $block = null) {
        $content = [];

        // For dynamic blocks, render them to get actual content
        $dynamic_blocks = ['categories', 'calendar', 'archives', 'page-list', 'latest-comments', 'latest-posts', 'tag-cloud', 'rss', 'search', 'social-links', 'navigation'];
        
        if (in_array($block_type, $dynamic_blocks) && $block && function_exists('render_block')) {
            $rendered_html = render_block($block);
            $parsed_content = $this->parse_dynamic_block_content($block_type, $attrs, $rendered_html);
            $content = array_merge([
                'html' => $rendered_html,
                'text' => strip_tags($rendered_html),
            ], $parsed_content);
            return $content;
        }

        switch ($block_type) {
            case 'heading':
                $content = [
                    'text' => isset($attrs['content']) ? $attrs['content'] : strip_tags($inner_html),
                    'level' => isset($attrs['level']) ? $attrs['level'] : 2,
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                ];
                break;

            case 'paragraph':
                $content = [
                    'text' => isset($attrs['content']) ? $attrs['content'] : strip_tags($inner_html),
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                    'dropCap' => isset($attrs['dropCap']) ? $attrs['dropCap'] : false,
                ];
                break;

            case 'image':
                $image_id = isset($attrs['id']) ? $attrs['id'] : null;
                $image_url = isset($attrs['url']) ? $attrs['url'] : '';
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                $content = [
                    'image_id' => $image_id,
                    'image_url' => $image_url,
                    'alt' => isset($attrs['alt']) ? $attrs['alt'] : '',
                    'caption' => isset($attrs['caption']) ? $attrs['caption'] : '',
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                ];
                break;

            case 'button':
                $content = [
                    'text' => isset($attrs['text']) ? $attrs['text'] : '',
                    'url' => isset($attrs['url']) ? $attrs['url'] : '',
                    'linkTarget' => isset($attrs['linkTarget']) ? $attrs['linkTarget'] : '',
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                ];
                break;

            case 'gallery':
                $images = [];
                if (isset($attrs['ids']) && is_array($attrs['ids'])) {
                    foreach ($attrs['ids'] as $img_id) {
                        $images[] = [
                            'id' => $img_id,
                            'url' => wp_get_attachment_image_url($img_id, 'full'),
                            'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true),
                        ];
                    }
                }
                $content = [
                    'images' => $images,
                    'columns' => isset($attrs['columns']) ? $attrs['columns'] : 3,
                    'linkTo' => isset($attrs['linkTo']) ? $attrs['linkTo'] : 'none',
                ];
                break;

            case 'quote':
                $content = [
                    'value' => isset($attrs['value']) ? $attrs['value'] : strip_tags($inner_html),
                    'citation' => isset($attrs['citation']) ? $attrs['citation'] : '',
                ];
                break;

            case 'list':
                $content = [
                    'values' => isset($attrs['values']) ? $attrs['values'] : strip_tags($inner_html),
                    'ordered' => isset($attrs['ordered']) ? $attrs['ordered'] : false,
                ];
                break;

            case 'code':
                $content = [
                    'content' => isset($attrs['content']) ? $attrs['content'] : strip_tags($inner_html),
                ];
                break;

            case 'html':
                $content = [
                    'html' => isset($attrs['content']) ? $attrs['content'] : $inner_html,
                ];
                break;

            case 'shortcode':
                // Extract shortcode text from various possible locations
                $shortcode_content = '';
                
                // Try attributes first
                if (isset($attrs['text']) && !empty($attrs['text'])) {
                    $shortcode_content = $attrs['text'];
                } elseif (isset($attrs['content']) && !empty($attrs['content'])) {
                    $shortcode_content = $attrs['content'];
                } else {
                    // Extract from innerHTML - look for shortcode pattern
                    $inner_clean = strip_tags($inner_html);
                    if (preg_match('/\[([^\]]+)\]/', $inner_clean, $matches)) {
                        $shortcode_content = $matches[0];
                    } else {
                        $shortcode_content = trim($inner_clean);
                    }
                }
                
                // Render the shortcode to get actual output
                $rendered_shortcode = '';
                if (!empty($shortcode_content) && function_exists('do_shortcode')) {
                    $rendered_shortcode = do_shortcode($shortcode_content);
                }
                
                $content = [
                    'shortcode' => $shortcode_content,
                    'html' => $rendered_shortcode,
                    'text' => strip_tags($rendered_shortcode),
                ];
                
                // Parse specific shortcode types for structured data
                if (!empty($shortcode_content)) {
                    $parsed_shortcode = $this->parse_shortcode_content($shortcode_content, $rendered_shortcode);
                    if (!empty($parsed_shortcode)) {
                        $content = array_merge($content, $parsed_shortcode);
                    }
                }
                break;

            case 'columns':
                // Columns block contains nested blocks
                $content = [
                    'columns' => isset($attrs['columns']) ? $attrs['columns'] : 2,
                ];
                break;

            case 'group':
            case 'cover':
            case 'media-text':
                // These blocks contain nested blocks, content is in inner blocks
                $content = [
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                ];
                break;

            case 'video':
                $content = [
                    'src' => isset($attrs['src']) ? $attrs['src'] : '',
                    'poster' => isset($attrs['poster']) ? $attrs['poster'] : '',
                    'autoplay' => isset($attrs['autoplay']) ? $attrs['autoplay'] : false,
                    'loop' => isset($attrs['loop']) ? $attrs['loop'] : false,
                ];
                break;

            case 'audio':
                $content = [
                    'src' => isset($attrs['src']) ? $attrs['src'] : '',
                ];
                break;

            case 'file':
                $file_id = isset($attrs['id']) ? $attrs['id'] : null;
                $content = [
                    'file_id' => $file_id,
                    'file_url' => $file_id ? wp_get_attachment_url($file_id) : '',
                    'href' => isset($attrs['href']) ? $attrs['href'] : '',
                    'fileName' => isset($attrs['fileName']) ? $attrs['fileName'] : '',
                ];
                break;

            case 'table':
                $content = [
                    'head' => isset($attrs['head']) ? $attrs['head'] : [],
                    'body' => isset($attrs['body']) ? $attrs['body'] : [],
                ];
                break;

            case 'separator':
                $content = [
                    'color' => isset($attrs['color']) ? $attrs['color'] : '',
                ];
                break;

            case 'spacer':
                $content = [
                    'height' => isset($attrs['height']) ? $attrs['height'] : '',
                ];
                break;

            default:
                // For any other block type, extract common attributes
                $content = [
                    'html' => $inner_html,
                    'text' => strip_tags($inner_html),
                ];
                break;
        }

        return $content;
    }

    /**
     * Parse rendered HTML from dynamic blocks to extract structured data
     */
    private function parse_dynamic_block_content($block_type, $attrs, $rendered_html) {
        $parsed = [];

        switch ($block_type) {
            case 'categories':
                // Extract categories from rendered HTML
                $categories = [];
                if (preg_match_all('/<li[^>]*class="[^"]*cat-item[^"]*"[^>]*>.*?<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>.*?<\/li>/s', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $categories[] = [
                            'url' => $match[1],
                            'name' => trim($match[2]),
                        ];
                    }
                }
                if (!empty($categories)) {
                    $parsed['categories'] = $categories;
                    $parsed['count'] = count($categories);
                }
                break;

            case 'page-list':
                // Extract pages from rendered HTML
                $pages = [];
                if (preg_match_all('/<li[^>]*>.*?<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>.*?<\/li>/s', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $pages[] = [
                            'url' => $match[1],
                            'title' => trim($match[2]),
                        ];
                    }
                }
                if (!empty($pages)) {
                    $parsed['pages'] = $pages;
                    $parsed['count'] = count($pages);
                }
                break;

            case 'latest-posts':
                // Extract posts from rendered HTML
                $posts = [];
                if (preg_match_all('/<li[^>]*>.*?<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>.*?(?:<time[^>]*>([^<]*)<\/time>)?.*?<\/li>/s', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $posts[] = [
                            'url' => $match[1],
                            'title' => trim($match[2]),
                            'date' => isset($match[3]) ? trim($match[3]) : '',
                        ];
                    }
                }
                if (!empty($posts)) {
                    $parsed['posts'] = $posts;
                    $parsed['count'] = count($posts);
                }
                break;

            case 'latest-comments':
                // Extract comments from rendered HTML
                $comments = [];
                if (preg_match_all('/<li[^>]*>.*?<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>.*?<span[^>]*>([^<]*)<\/span>.*?<\/li>/s', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $comments[] = [
                            'url' => $match[1],
                            'author' => trim($match[2]),
                            'excerpt' => trim($match[3]),
                        ];
                    }
                }
                if (!empty($comments)) {
                    $parsed['comments'] = $comments;
                    $parsed['count'] = count($comments);
                }
                break;

            case 'calendar':
                // Extract calendar structure
                $calendar_data = [];
                // Extract month/year from rendered HTML
                if (preg_match('/<caption[^>]*>([^<]*)<\/caption>/', $rendered_html, $match)) {
                    $calendar_data['caption'] = trim($match[1]);
                }
                // Extract dates
                $dates = [];
                if (preg_match_all('/<td[^>]*>.*?<a[^>]*>(\d+)<\/a>.*?<\/td>/s', $rendered_html, $matches)) {
                    foreach ($matches[1] as $day) {
                        $dates[] = (int)$day;
                    }
                }
                if (!empty($dates)) {
                    $calendar_data['dates'] = $dates;
                }
                if (!empty($calendar_data)) {
                    $parsed['calendar'] = $calendar_data;
                }
                break;

            case 'archives':
                // Extract archives from rendered HTML
                $archives = [];
                if (preg_match_all('/<li[^>]*>.*?<a[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>.*?<\/li>/s', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $archives[] = [
                            'url' => $match[1],
                            'title' => trim($match[2]),
                        ];
                    }
                }
                if (!empty($archives)) {
                    $parsed['archives'] = $archives;
                    $parsed['count'] = count($archives);
                }
                break;

            case 'tag-cloud':
                // Extract tags from rendered HTML
                $tags = [];
                if (preg_match_all('/<a[^>]*href="([^"]*)"[^>]*style="[^"]*font-size:[^"]*"[^>]*>([^<]*)<\/a>/', $rendered_html, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        // Extract font size to determine tag weight
                        if (preg_match('/font-size:\s*([\d.]+)pt/', $match[0], $size_match)) {
                            $tags[] = [
                                'url' => $match[1],
                                'name' => trim($match[2]),
                                'size' => (float)$size_match[1],
                            ];
                        } else {
                            $tags[] = [
                                'url' => $match[1],
                                'name' => trim($match[2]),
                            ];
                        }
                    }
                }
                if (!empty($tags)) {
                    $parsed['tags'] = $tags;
                    $parsed['count'] = count($tags);
                }
                break;

            case 'search':
                // Search block doesn't need parsing, it's just a form
                $parsed['form_html'] = $rendered_html;
                break;
        }

        return $parsed;
    }

    /**
     * Parse shortcode content to extract structured data
     */
    private function parse_shortcode_content($shortcode_text, $rendered_html) {
        $parsed = [];
        
        // Extract shortcode name and attributes
        if (preg_match('/\[(\w+)([^\]]*)\]/', $shortcode_text, $matches)) {
            $shortcode_name = $matches[1];
            $shortcode_attrs = $matches[2];
            
            $parsed['shortcode_name'] = $shortcode_name;
            
            // Extract attributes from shortcode
            $attrs = [];
            if (preg_match_all('/(\w+)="([^"]*)"/', $shortcode_attrs, $attr_matches, PREG_SET_ORDER)) {
                foreach ($attr_matches as $attr_match) {
                    $attrs[$attr_match[1]] = $attr_match[2];
                }
            }
            if (!empty($attrs)) {
                $parsed['shortcode_attributes'] = $attrs;
            }
            
            // Parse specific shortcode types
            switch ($shortcode_name) {
                case 'contact-form-7':
                    $parsed['form_type'] = 'contact-form-7';
                    if (isset($attrs['id'])) {
                        $parsed['form_id'] = $attrs['id'];
                    }
                    if (isset($attrs['title'])) {
                        $parsed['form_title'] = $attrs['title'];
                    }
                    // Extract form fields from rendered HTML
                    $form_fields = [];
                    if (preg_match_all('/<input[^>]*name="([^"]*)"[^>]*>/', $rendered_html, $field_matches)) {
                        foreach ($field_matches[1] as $field_name) {
                            if (!in_array($field_name, ['_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post'])) {
                                $form_fields[] = $field_name;
                            }
                        }
                    }
                    if (preg_match_all('/<textarea[^>]*name="([^"]*)"[^>]*>/', $rendered_html, $textarea_matches)) {
                        $form_fields = array_merge($form_fields, $textarea_matches[1]);
                    }
                    if (preg_match_all('/<select[^>]*name="([^"]*)"[^>]*>/', $rendered_html, $select_matches)) {
                        $form_fields = array_merge($form_fields, $select_matches[1]);
                    }
                    if (!empty($form_fields)) {
                        $parsed['form_fields'] = array_unique($form_fields);
                    }
                    break;
                    
                case 'woocommerce_cart':
                case 'woocommerce_checkout':
                case 'woocommerce_order_tracking':
                case 'woocommerce_my_account':
                    $parsed['woocommerce_component'] = $shortcode_name;
                    break;
                    
                case 'gallery':
                    // Extract gallery image IDs
                    if (isset($attrs['ids'])) {
                        $image_ids = explode(',', $attrs['ids']);
                        $parsed['gallery_images'] = array_map('intval', $image_ids);
                        $parsed['image_count'] = count($parsed['gallery_images']);
                    }
                    break;
                    
                case 'embed':
                    // Extract embed URL
                    if (isset($attrs['url'])) {
                        $parsed['embed_url'] = $attrs['url'];
                    }
                    break;
            }
        }
        
        return $parsed;
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
        // In a full implementation, you'd check Elementor's conditions
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

    /**
     * Enhance header data with extracted logos, icons, menus, etc.
     */
    private function enhance_header_data($header_data) {
        $enhanced = $header_data;
        $enhanced['extracted'] = [
            'site_title' => '',
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

