<?php

/**
 * Content Parser Trait
 * Shared parsing methods for Elementor and Gutenberg content
 */
trait RESTBridge_Content_Parser {
    protected function parse_elementor_content($page, $elementor_data) {
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
    protected function parse_section($section, $index) {
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

        // Extract background image and color from settings BEFORE cleaning
        $background = $this->extract_section_background($section_settings);
        
        $result = [
            'index' => $index,
            'section_number' => $index + 1, // Human-readable: 1, 2, 3, etc.
            'position' => $this->get_section_position($index), // "first", "second", "third", etc.
            'type' => $el_type,
            'id' => isset($section['id']) ? $section['id'] : '',
            'settings' => $this->clean_settings($section_settings),
        ];
        
        // Add background information if available
        if (!empty($background)) {
            $result['background'] = $background;
        }

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
    protected function parse_column($column) {
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
    protected function parse_widget($widget) {
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
    protected function extract_widget_content($widget_type, $settings) {
        $content = [];

        switch ($widget_type) {
            case 'heading':
                // Clean title: remove HTML tags and <br /> tags
                $title = isset($settings['title']) ? $settings['title'] : '';
                $title = preg_replace('/<br\s*\/?>/i', ' ', $title);
                $title = wp_strip_all_tags($title);
                $title = preg_replace('/\s+/', ' ', $title);
                $title = trim($title);
                
                $content = [
                    'title' => $title,
                    'size' => isset($settings['size']) ? $settings['size'] : 'default',
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'text-editor':
                // Clean description: remove HTML tags and <br /> tags
                $description = isset($settings['editor']) ? $settings['editor'] : '';
                $description = preg_replace('/<br\s*\/?>/i', ' ', $description);
                $description = wp_strip_all_tags($description);
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description);
                
                $content = [
                    'description' => $description,
                    'alignment' => isset($settings['align']) ? $settings['align'] : '',
                ];
                break;

            case 'button':
                // Clean button text
                $text = isset($settings['text']) ? $settings['text'] : '';
                $text = preg_replace('/<br\s*\/?>/i', ' ', $text);
                $text = wp_strip_all_tags($text);
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                
                $content = [
                    'text' => $text,
                    'url' => isset($settings['link']['url']) ? $settings['link']['url'] : '',
                ];
                break;

            case 'image':
                $image_id = isset($settings['image']['id']) ? $settings['image']['id'] : (isset($settings['image']) && is_numeric($settings['image']) ? $settings['image'] : null);
                $image_url = isset($settings['image']['url']) ? $settings['image']['url'] : '';
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                // Clean caption
                $caption = isset($settings['caption']) ? $settings['caption'] : '';
                $caption = preg_replace('/<br\s*\/?>/i', ' ', $caption);
                $caption = wp_strip_all_tags($caption);
                $caption = preg_replace('/\s+/', ' ', $caption);
                $caption = trim($caption);
                
                $content = [
                    'image_url' => $image_url,
                    'image_id' => $image_id,
                    'alt' => isset($settings['image']['alt']) ? $settings['image']['alt'] : '',
                    'caption' => $caption,
                ];
                break;

            case 'image-box':
                $image_id = isset($settings['image']['id']) ? $settings['image']['id'] : null;
                $image_url = isset($settings['image']['url']) ? $settings['image']['url'] : '';
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }
                
                // Clean title
                $title = isset($settings['title_text']) ? $settings['title_text'] : '';
                $title = preg_replace('/<br\s*\/?>/i', ' ', $title);
                $title = wp_strip_all_tags($title);
                $title = preg_replace('/\s+/', ' ', $title);
                $title = trim($title);
                
                // Clean description
                $description = isset($settings['description_text']) ? $settings['description_text'] : '';
                $description = preg_replace('/<br\s*\/?>/i', ' ', $description);
                $description = wp_strip_all_tags($description);
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description);
                
                $content = [
                    'title' => $title,
                    'description' => $description,
                    'image_url' => $image_url,
                    'image_id' => $image_id,
                ];
                break;

            case 'icon-box':
                // Clean title
                $title = isset($settings['title_text']) ? $settings['title_text'] : '';
                $title = preg_replace('/<br\s*\/?>/i', ' ', $title);
                $title = wp_strip_all_tags($title);
                $title = preg_replace('/\s+/', ' ', $title);
                $title = trim($title);
                
                // Clean description
                $description = isset($settings['description_text']) ? $settings['description_text'] : '';
                $description = preg_replace('/<br\s*\/?>/i', ' ', $description);
                $description = wp_strip_all_tags($description);
                $description = preg_replace('/\s+/', ' ', $description);
                $description = trim($description);
                
                $content = [
                    'title' => $title,
                    'description' => $description,
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

            case 'woocommerce-products':
            case 'woocommerce-products-grid':
            case 'products':
            case 'product-grid':
            case 'wc-products':
            case 'wc-products-grid':
            case 'jet-woo-products':
            case 'jet-woo-products-grid':
                // WooCommerce Products Widget - Extract product data dynamically
                $content = $this->extract_woocommerce_products($settings);
                break;

            case 'woocommerce-product':
            case 'wc-product':
            case 'product':
            case 'jet-woo-product':
                // Single WooCommerce Product Widget
                $content = $this->extract_single_product($settings);
                break;

            case 'kalles-categories-list':
            case 'categories-list':
            case 'product-categories':
            case 'woocommerce-categories':
                // Categories list widget - Extract full category data
                $content = $this->extract_product_categories($settings);
                break;

            case 'posts':
            case 'posts-grid':
            case 'blog-posts':
            case 'latest-posts':
            case 'post-grid':
            case 'jet-blog':
            case 'jet-blog-posts':
            case 'jet-posts':
            case 'jet-posts-grid':
            case 'wp-widget-recent-posts':
            case 'kalles-blog':
            case 'kalles-blog-posts':
                // Blog Posts Widget - Extract blog post data dynamically
                $content = $this->extract_blog_posts($settings);
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
    protected function clean_settings($settings) {
        if (!is_array($settings)) {
            return [];
        }

        $cleaned = [];
        
        // Skip design-related keys (styling, spacing, layout)
        $skip_keys = [
            '__globals__', 
            '_element_id',
            // Padding & Margin
            'padding', 'padding_tablet', 'padding_mobile',
            'margin', 'margin_tablet', 'margin_mobile',
            'padding_top', 'padding_right', 'padding_bottom', 'padding_left',
            'margin_top', 'margin_right', 'margin_bottom', 'margin_left',
            // Background
            'background', 'background_color', 'background_image', 'background_overlay',
            'background_hover', 'background_hover_color', 'background_hover_image',
            // Border
            'border', 'border_color', 'border_width', 'border_radius',
            'border_top', 'border_right', 'border_bottom', 'border_left',
            // Typography & Text Styling
            'typography', 'font_family', 'font_size', 'font_weight', 'font_style',
            'text_color', 'text_shadow', 'text_stroke', 'text_decoration',
            'line_height', 'letter_spacing', 'word_spacing',
            // Layout & Spacing
            'flex', 'flex_direction', 'flex_wrap', 'justify_content', 'align_items',
            'gap', 'gap_column', 'gap_row', 'width', 'height', 'min_height', 'max_height',
            'z_index', 'position', 'top', 'right', 'bottom', 'left',
            // Animation & Effects
            'animation', 'transition', 'transform', 'opacity', 'box_shadow',
            'hover_animation', 'hover_color', 'hover_background',
            // Responsive breakpoints
            '_responsive', 'desktop', 'tablet', 'mobile',
            // Elementor specific design settings
            'content_width', 'column_gap', 'row_gap', 'space_between_widgets',
            'section_layout', 'section_width', 'section_height',
            'content_position', 'height_match', 'stretch_section',
        ];

        foreach ($settings as $key => $value) {
            // Skip design-related keys
            if (in_array($key, $skip_keys) || 
                strpos($key, 'padding') !== false || 
                strpos($key, 'margin') !== false ||
                strpos($key, 'background') !== false ||
                strpos($key, 'border') !== false ||
                strpos($key, 'typography') !== false ||
                strpos($key, 'font_') !== false ||
                strpos($key, 'text_') === 0 ||
                strpos($key, 'hover_') === 0 ||
                strpos($key, 'animation') !== false ||
                strpos($key, '_tablet') !== false ||
                strpos($key, '_mobile') !== false) {
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
    protected function parse_gutenberg_content($page, $content) {
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
        $block_counter = 0; // Track actual block position for identification

        foreach ($blocks as $block_index => $block) {
            // Skip empty blocks
            if (empty($block['blockName']) && empty(trim($block['innerHTML']))) {
                continue;
            }

            $parsed_block = $this->parse_gutenberg_block($block, $block_index, $block_counter);
            if ($parsed_block) {
                $parsed_blocks[] = $parsed_block;
                $block_counter++; // Increment only for non-empty blocks
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
    protected function parse_gutenberg_block($block, $index, $block_position = null) {
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

        // Use block_position if provided (for top-level blocks), otherwise use index
        $actual_position = ($block_position !== null) ? $block_position : $index;

        $block_data = [
            'index' => $index,
            'block_number' => $actual_position + 1, // Human-readable: 1, 2, 3, etc.
            'position' => $this->get_block_position($actual_position), // "first", "second", "third", etc.
            'type' => $block_type,
            'block_name' => $block_name,
            'content' => $this->extract_gutenberg_block_content($block_type, $attrs, $inner_html, $block),
            'attributes' => $this->clean_settings($attrs),
            'raw_content' => $inner_html,
        ];

        // Add identifier from content if available (title, text, etc.)
        $identifier = $this->get_block_identifier($block_type, $block_data['content'], $attrs);
        if (!empty($identifier)) {
            $block_data['identifier'] = $identifier;
        }

        // Parse nested blocks (nested blocks don't get position numbers, they're part of parent)
        if (!empty($inner_blocks)) {
            $block_data['blocks'] = [];
            foreach ($inner_blocks as $inner_index => $inner_block) {
                $parsed_inner = $this->parse_gutenberg_block($inner_block, $inner_index, null); // null = nested, no position
                if ($parsed_inner) {
                    $block_data['blocks'][] = $parsed_inner;
                }
            }
            $block_data['total_blocks'] = count($block_data['blocks']);
        }

        return $block_data;
    }

    /**
     * Get human-readable block position (first, second, third, etc.)
     */
    protected function get_block_position($index) {
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
        
        // For positions beyond 10, return "block-" + number
        return 'block-' . ($index + 1);
    }

    /**
     * Get a human-readable identifier for a block (title, text excerpt, etc.)
     */
    protected function get_block_identifier($block_type, $content, $attrs) {
        $identifier = '';
        
        // Try to get identifier from content based on block type
        switch ($block_type) {
            case 'heading':
                if (isset($content['text']) && !empty($content['text'])) {
                    $identifier = substr($content['text'], 0, 50); // First 50 chars
                    if (strlen($content['text']) > 50) {
                        $identifier .= '...';
                    }
                }
                break;
                
            case 'paragraph':
                if (isset($content['text']) && !empty($content['text'])) {
                    $identifier = substr($content['text'], 0, 50);
                    if (strlen($content['text']) > 50) {
                        $identifier .= '...';
                    }
                }
                break;
                
            case 'image':
                if (isset($content['image_url']) && !empty($content['image_url'])) {
                    $identifier = 'Image: ' . basename($content['image_url']);
                } elseif (isset($content['alt']) && !empty($content['alt'])) {
                    $identifier = 'Image: ' . $content['alt'];
                }
                break;
                
            case 'columns':
                if (isset($content['columns'])) {
                    $identifier = $content['columns'] . ' Column Layout';
                }
                break;
                
            case 'home-banner-section':
                $identifier_source = '';
                if (!empty($attrs['title'])) {
                    $identifier_source = $attrs['title'];
                } elseif (isset($block['innerBlocks'][0]['innerHTML'])) {
                    $identifier_source = $block['innerBlocks'][0]['innerHTML'];
                } elseif (!empty($content['title'])) {
                    $identifier_source = $content['title'];
                }
                $identifier_source = wp_strip_all_tags($identifier_source);
                if (!empty($identifier_source)) {
                    $identifier = substr($identifier_source, 0, 50);
                    if (strlen($identifier_source) > 50) {
                        $identifier .= '...';
                    }
                }
                break;
                
            case 'quote':
                if (isset($content['value']) && !empty($content['value'])) {
                    $identifier = substr($content['value'], 0, 50);
                    if (strlen($content['value']) > 50) {
                        $identifier .= '...';
                    }
                }
                break;
        }
        
        return $identifier;
    }

    /**
     * Extract content from Gutenberg block based on block type
     */
    protected function extract_gutenberg_block_content($block_type, $attrs, $inner_html, $block = null) {
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
                // Clean heading text: replace <br /> with spaces
                $heading_text = isset($attrs['content']) ? $attrs['content'] : $inner_html;
                $heading_text = preg_replace('/<br\s*\/?>/i', ' ', $heading_text);
                $heading_text = wp_strip_all_tags($heading_text);
                $heading_text = preg_replace('/\s+/', ' ', $heading_text);
                $heading_text = trim($heading_text);
                
                $content = [
                    'text' => $heading_text,
                    'level' => isset($attrs['level']) ? $attrs['level'] : 2,
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                ];
                break;

            case 'paragraph':
                // Clean paragraph text: replace <br /> with spaces
                $para_text = isset($attrs['content']) ? $attrs['content'] : $inner_html;
                $para_text = preg_replace('/<br\s*\/?>/i', ' ', $para_text);
                $para_text = wp_strip_all_tags($para_text);
                $para_text = preg_replace('/\s+/', ' ', $para_text);
                $para_text = trim($para_text);
                
                $content = [
                    'text' => $para_text,
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
                // Clean quote text: replace <br /> with spaces
                $quote_text = isset($attrs['value']) ? $attrs['value'] : $inner_html;
                $quote_text = preg_replace('/<br\s*\/?>/i', ' ', $quote_text);
                $quote_text = wp_strip_all_tags($quote_text);
                $quote_text = preg_replace('/\s+/', ' ', $quote_text);
                $quote_text = trim($quote_text);
                
                $content = [
                    'value' => $quote_text,
                    'citation' => isset($attrs['citation']) ? $attrs['citation'] : '',
                ];
                break;

            case 'list':
                // Clean list text: replace <br /> with spaces
                $list_text = isset($attrs['values']) ? $attrs['values'] : $inner_html;
                $list_text = preg_replace('/<br\s*\/?>/i', ' ', $list_text);
                $list_text = wp_strip_all_tags($list_text);
                $list_text = preg_replace('/\s+/', ' ', $list_text);
                $list_text = trim($list_text);
                
                $content = [
                    'values' => $list_text,
                    'ordered' => isset($attrs['ordered']) ? $attrs['ordered'] : false,
                ];
                break;

            case 'code':
                // Code blocks should preserve formatting, but clean <br /> tags
                $code_text = isset($attrs['content']) ? $attrs['content'] : $inner_html;
                $code_text = preg_replace('/<br\s*\/?>/i', "\n", $code_text);
                $code_text = wp_strip_all_tags($code_text);
                
                $content = [
                    'content' => $code_text,
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

            case 'home-banner-section':
                $image_id = isset($attrs['imageId']) ? intval($attrs['imageId']) : 0;
                $image_url = isset($attrs['imageUrl']) ? $attrs['imageUrl'] : '';
                if ($image_id > 0 && empty($image_url)) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                }

                $title_value = '';
                $description_value = '';
                $button_text_value = '';
                $button_url_value = '';

                $inner_blocks_data = $block['innerBlocks'] ?? [];
                foreach ($inner_blocks_data as $inner) {
                    $inner_name = $inner['blockName'] ?? '';
                    if ($inner_name === 'core/heading') {
                        $title_value = $inner['attrs']['content'] ?? ($inner['innerHTML'] ?? '');
                    } elseif ($inner_name === 'core/paragraph') {
                        $description_value = $inner['attrs']['content'] ?? ($inner['innerHTML'] ?? '');
                    } elseif ($inner_name === 'core/buttons' && !empty($inner['innerBlocks'])) {
                        $button_block = $inner['innerBlocks'][0];
                        $button_text_value = $button_block['attrs']['text'] ?? ($button_block['innerHTML'] ?? '');
                        $button_url_value = $button_block['attrs']['url'] ?? ($button_block['attrs']['href'] ?? '');
                    }
                }

                $content = [
                    'title' => wp_strip_all_tags($title_value),
                    'description' => wp_strip_all_tags($description_value),
                    'button' => [
                        'text' => wp_strip_all_tags($button_text_value),
                        'url' => $button_url_value,
                    ],
                    'image' => [
                        'id' => $image_id,
                        'url' => $image_url,
                    ],
                    'discount' => [
                        'percent' => isset($attrs['discountPercent']) ? $attrs['discountPercent'] : '',
                        'show' => isset($attrs['showDiscountBadge']) ? (bool) $attrs['showDiscountBadge'] : true,
                    ],
                    'background_color' => isset($attrs['backgroundColor']) ? $attrs['backgroundColor'] : '',
                    'align' => isset($attrs['align']) ? $attrs['align'] : '',
                    'inner_blocks' => $inner_blocks_data,
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
                // For Classic blocks (empty block_type) or unknown block types
                // Parse HTML content to extract structured elements
                if (empty($block_type) && !empty($inner_html)) {
                    // This is a Classic block - parse HTML to extract structured content
                    $content = $this->parse_classic_block_html($inner_html);
                } else {
                // For any other block type, extract common attributes
                $content = [
                    'html' => $inner_html,
                    'text' => strip_tags($inner_html),
                ];
                }
                break;
        }

        return $content;
    }

    /**
     * Parse Classic block HTML content into structured elements
     */
    protected function parse_classic_block_html($html) {
        // Clean HTML: replace <br /> and <br> with spaces, then strip all tags
        $clean_text = preg_replace('/<br\s*\/?>/i', ' ', $html);
        $clean_text = wp_strip_all_tags($clean_text);
        $clean_text = preg_replace('/\s+/', ' ', $clean_text); // Multiple spaces to single space
        $clean_text = trim($clean_text);
        
        $parsed = [
            'html' => $html,
            'text' => $clean_text,
            'elements' => [],
        ];

        // Extract headings
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h[1-6]>/is', $html, $heading_matches, PREG_SET_ORDER)) {
            foreach ($heading_matches as $match) {
                // Clean heading text: replace <br /> with spaces
                $heading_text = preg_replace('/<br\s*\/?>/i', ' ', $match[2]);
                $heading_text = wp_strip_all_tags($heading_text);
                $heading_text = preg_replace('/\s+/', ' ', $heading_text);
                $heading_text = trim($heading_text);
                
                $parsed['elements'][] = [
                    'type' => 'heading',
                    'level' => (int) $match[1],
                    'text' => $heading_text,
                    'html' => $match[0],
                ];
            }
        }

        // Extract paragraphs
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $para_matches, PREG_SET_ORDER)) {
            foreach ($para_matches as $match) {
                // Clean paragraph text: replace <br /> with spaces
                $para_text = preg_replace('/<br\s*\/?>/i', ' ', $match[1]);
                $para_text = wp_strip_all_tags($para_text);
                $para_text = preg_replace('/\s+/', ' ', $para_text);
                $para_text = trim($para_text);
                
                if (!empty($para_text)) {
                    $parsed['elements'][] = [
                        'type' => 'paragraph',
                        'text' => $para_text,
                        'html' => $match[0],
                    ];
                }
            }
        }

        // Extract images
        if (preg_match_all('/<img[^>]+>/i', $html, $img_matches)) {
            foreach ($img_matches[0] as $img_tag) {
                preg_match('/src=["\']([^"\']+)["\']/', $img_tag, $src_match);
                preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_match);
                preg_match('/width=["\']([^"\']+)["\']/', $img_tag, $width_match);
                preg_match('/height=["\']([^"\']+)["\']/', $img_tag, $height_match);
                
                $parsed['elements'][] = [
                    'type' => 'image',
                    'url' => isset($src_match[1]) ? $src_match[1] : '',
                    'alt' => isset($alt_match[1]) ? $alt_match[1] : '',
                    'width' => isset($width_match[1]) ? (int) $width_match[1] : null,
                    'height' => isset($height_match[1]) ? (int) $height_match[1] : null,
                    'html' => $img_tag,
                ];
            }
        }

        // Extract links
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $link_matches, PREG_SET_ORDER)) {
            foreach ($link_matches as $match) {
                // Clean link text: replace <br /> with spaces
                $link_text = preg_replace('/<br\s*\/?>/i', ' ', $match[2]);
                $link_text = wp_strip_all_tags($link_text);
                $link_text = preg_replace('/\s+/', ' ', $link_text);
                $link_text = trim($link_text);
                
                $parsed['elements'][] = [
                    'type' => 'link',
                    'url' => $match[1],
                    'text' => $link_text,
                    'html' => $match[0],
                ];
            }
        }

        // Extract lists
        if (preg_match_all('/<(ul|ol)[^>]*>(.*?)<\/\1>/is', $html, $list_matches, PREG_SET_ORDER)) {
            foreach ($list_matches as $match) {
                $list_type = $match[1] === 'ul' ? 'unordered' : 'ordered';
                preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $match[2], $item_matches);
                
                $items = [];
                if (!empty($item_matches[1])) {
                    foreach ($item_matches[1] as $item) {
                        // Clean list item text: replace <br /> with spaces
                        $item_text = preg_replace('/<br\s*\/?>/i', ' ', $item);
                        $item_text = wp_strip_all_tags($item_text);
                        $item_text = preg_replace('/\s+/', ' ', $item_text);
                        $item_text = trim($item_text);
                        $items[] = $item_text;
                    }
                }
                
                $parsed['elements'][] = [
                    'type' => 'list',
                    'list_type' => $list_type,
                    'items' => $items,
                    'html' => $match[0],
                ];
            }
        }

        return $parsed;
    }

    /**
     * Parse rendered HTML from dynamic blocks to extract structured data
     */
    protected function parse_dynamic_block_content($block_type, $attrs, $rendered_html) {
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
    protected function parse_shortcode_content($shortcode_text, $rendered_html) {
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
     * Extract plain text content from parsed Elementor or Gutenberg content
     * Returns all text content without HTML tags, organized row-wise
     * Includes image URLs, links, and all text content
     */
    protected function extract_plain_text_content($parsed_data) {
        $text_parts = [];

        // Extract from Elementor sections (row-wise)
        if (isset($parsed_data['sections']) && is_array($parsed_data['sections'])) {
            foreach ($parsed_data['sections'] as $section_index => $section) {
                // Each section represents a row
                $section_texts = [];
                $this->extract_text_from_section($section, $section_texts);
                
                // Add section texts to main array
                if (!empty($section_texts)) {
                    // Add a separator between sections if not first section
                    if (!empty($text_parts)) {
                        $text_parts[] = ''; // Empty line between sections/rows
                    }
                    $text_parts = array_merge($text_parts, $section_texts);
                }
            }
        }

        // Extract from Gutenberg blocks (row-wise)
        if (isset($parsed_data['blocks']) && is_array($parsed_data['blocks'])) {
            foreach ($parsed_data['blocks'] as $block_index => $block) {
                $block_texts = [];
                $this->extract_text_from_block($block, $block_texts);
                
                if (!empty($block_texts)) {
                    if (!empty($text_parts)) {
                        $text_parts[] = ''; // Empty line between blocks/rows
                    }
                    $text_parts = array_merge($text_parts, $block_texts);
                }
            }
        }

        // Extract from widgets (theme footer/header) - organized by widget area
        if (isset($parsed_data['widgets']) && is_array($parsed_data['widgets'])) {
            // Sort widgets by key to maintain order (footer-1, footer-2, footer-3, footer-4)
            $sorted_widgets = $parsed_data['widgets'];
            ksort($sorted_widgets);
            
            $row_index = 0;
            foreach ($sorted_widgets as $widget_key => $widget_data) {
                // Skip footer bottom area for now, we'll handle it separately
                if ($widget_key === 'footer' || strpos($widget_key, 'footer__bot') !== false) {
                    continue;
                }
                
                $row_index++;
                
                // Handle structured data (new format)
                if (isset($widget_data['title']) || isset($widget_data['menu_items']) || isset($widget_data['text_content'])) {
                    $widget_texts = [];
                    
                    // Add title
                    if (!empty($widget_data['title'])) {
                        $widget_texts[] = $widget_data['title'];
                    }
                    
                    // Add menu items
                    if (isset($widget_data['menu_items']) && is_array($widget_data['menu_items'])) {
                        foreach ($widget_data['menu_items'] as $item) {
                            if (!empty($item['title'])) {
                                $widget_texts[] = $item['title'];
                            }
                            if (!empty($item['url'])) {
                                $widget_texts[] = $item['url'];
                            }
                        }
                    }
                    
                    // Add images
                    if (isset($widget_data['images']) && is_array($widget_data['images'])) {
                        foreach ($widget_data['images'] as $img) {
                            if (!empty($img['url'])) {
                                $widget_texts[] = $img['url'];
                            }
                            if (!empty($img['alt'])) {
                                $widget_texts[] = $img['alt'];
                            }
                        }
                    }
                    
                    // Add links
                    if (isset($widget_data['links']) && is_array($widget_data['links'])) {
                        foreach ($widget_data['links'] as $link) {
                            if (!empty($link['title'])) {
                                $widget_texts[] = $link['title'];
                            }
                            if (!empty($link['url'])) {
                                $widget_texts[] = $link['url'];
                            }
                        }
                    }
                    
                    // Add text content
                    if (isset($widget_data['text_content']) && is_array($widget_data['text_content'])) {
                        foreach ($widget_data['text_content'] as $text) {
                            if (!empty($text)) {
                                $widget_texts[] = $text;
                            }
                        }
                    }
                    
                    // Add form fields
                    if (isset($widget_data['form_fields']) && is_array($widget_data['form_fields'])) {
                        foreach ($widget_data['form_fields'] as $field) {
                            if (!empty($field['value'])) {
                                $widget_texts[] = $field['value'];
                            }
                        }
                    }
                    
                    // Add icons
                    if (isset($widget_data['icons']) && is_array($widget_data['icons'])) {
                        foreach ($widget_data['icons'] as $icon) {
                            if (!empty($icon)) {
                                $widget_texts[] = $icon;
                            }
                        }
                    }
                } elseif (isset($widget_data['html'])) {
                    // Fallback to HTML parsing (old format)
                    $html = $widget_data['html'];
                    $widget_texts = [];
                    
                    // Extract image URLs (full URLs)
                    if (preg_match_all('/<img[^>]*(?:src|data-src)=["\']([^"\']+)["\']/i', $html, $img_matches)) {
                        foreach ($img_matches[1] as $img_url) {
                            $img_url = trim($img_url);
                            // Skip data URIs and empty URLs
                            if (!empty($img_url) && strpos($img_url, 'data:') !== 0) {
                                // Convert relative URLs to absolute
                                if (strpos($img_url, 'http') !== 0) {
                                    $img_url = site_url($img_url);
                                }
                                $widget_texts[] = $img_url;
                            }
                        }
                    }
                    
                    // Extract image alt text
                    if (preg_match_all('/<img[^>]*alt=["\']([^"\']+)["\']/i', $html, $alt_matches)) {
                        foreach ($alt_matches[1] as $alt) {
                            $alt = trim($alt);
                            if (!empty($alt)) {
                                $widget_texts[] = $alt;
                            }
                        }
                    }
                    
                    // Extract headings (h1-h6)
                    if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $heading_matches)) {
                        foreach ($heading_matches[1] as $heading) {
                            $heading_text = strip_tags($heading);
                            $heading_text = trim($heading_text);
                            if (!empty($heading_text)) {
                                $widget_texts[] = $heading_text;
                            }
                        }
                    }
                    
                    // Extract form labels
                    if (preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $html, $label_matches)) {
                        foreach ($label_matches[1] as $label) {
                            $label_text = strip_tags($label);
                            $label_text = trim($label_text);
                            if (!empty($label_text)) {
                                $widget_texts[] = $label_text;
                            }
                        }
                    }
                    
                    // Extract input placeholders
                    if (preg_match_all('/placeholder=["\']([^"\']+)["\']/i', $html, $placeholder_matches)) {
                        foreach ($placeholder_matches[1] as $placeholder) {
                            $placeholder = trim($placeholder);
                            if (!empty($placeholder)) {
                                $widget_texts[] = $placeholder;
                            }
                        }
                    }
                    
                    // Extract button text
                    if (preg_match_all('/<(button|input)[^>]*type=["\'](submit|button)["\'][^>]*>(.*?)<\/(button|input)>/is', $html, $button_matches)) {
                        foreach ($button_matches[3] as $button_text) {
                            $btn_text = strip_tags($button_text);
                            $btn_text = trim($btn_text);
                            if (!empty($btn_text)) {
                                $widget_texts[] = $btn_text;
                            }
                        }
                    }
                    // Extract value attribute for submit buttons
                    if (preg_match_all('/<input[^>]*type=["\'](submit|button)["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $value_matches)) {
                        foreach ($value_matches[2] as $value) {
                            $value = trim($value);
                            if (!empty($value)) {
                                $widget_texts[] = $value;
                            }
                        }
                    }
                    
                    // Extract all links with text and URLs
                    if (preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $link_matches, PREG_SET_ORDER)) {
                        foreach ($link_matches as $link_match) {
                            $link_url = trim($link_match[1]);
                            $link_text = strip_tags($link_match[2]);
                            $link_text = trim($link_text);
                            
                            // Convert relative URLs to absolute
                            if (!empty($link_url) && strpos($link_url, 'http') !== 0 && strpos($link_url, 'mailto:') !== 0 && strpos($link_url, 'tel:') !== 0) {
                                $link_url = site_url($link_url);
                            }
                            
                            // Add link text if it has visible content
                            if (!empty($link_text) && !preg_match('/^[\s]*$/', $link_text)) {
                                $widget_texts[] = $link_text;
                            }
                            // Add link URL
                            if (!empty($link_url)) {
                                $widget_texts[] = $link_url;
                            }
                        }
                    }
                    
                    // Extract link href text for email and tel links (as fallback)
                    if (preg_match_all('/href=["\'](mailto:|tel:)([^"\']+)["\']/i', $html, $contact_matches)) {
                        foreach ($contact_matches[2] as $contact) {
                            $contact = trim($contact);
                            if (!empty($contact)) {
                                $widget_texts[] = $contact;
                            }
                        }
                    }
                    
                    // Extract list items
                    if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $html, $li_matches)) {
                        foreach ($li_matches[1] as $li) {
                            $li_text = strip_tags($li);
                            $li_text = trim($li_text);
                            if (!empty($li_text)) {
                                $widget_texts[] = $li_text;
                            }
                        }
                    }
                    
                    // Extract paragraph text (including all text content)
                    // Use non-greedy match and handle nested tags - extract ALL paragraphs
                    if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $p_matches)) {
                        foreach ($p_matches[1] as $p_text) {
                            // Remove icon tags but keep their parent text
                            $p_text_clean = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $p_text);
                            // Remove link tags but keep their text content
                            $p_text_clean = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $p_text_clean);
                            // Remove span tags but keep their text
                            $p_text_clean = preg_replace('/<span[^>]*>(.*?)<\/span>/is', '$1', $p_text_clean);
                            // Extract all text including nested elements
                            $p_content = strip_tags($p_text_clean);
                            // Decode HTML entities
                            $p_content = html_entity_decode($p_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $p_content = trim($p_content);
                            // Remove extra whitespace but preserve structure
                            $p_content = preg_replace('/\s+/', ' ', $p_content);
                            
                            // If paragraph has <br> tags, split it
                            if (strpos($p_text_clean, '<br') !== false) {
                                $p_lines = preg_split('/<br[^>]*>/i', $p_content);
                                foreach ($p_lines as $p_line) {
                                    $p_line = trim($p_line);
                                    if (!empty($p_line) && strlen($p_line) > 1) {
                                        $widget_texts[] = $p_line;
                                    }
                                }
                            } else {
                                // Add the full paragraph text
                                if (!empty($p_content) && strlen($p_content) > 1) {
                                    $widget_texts[] = $p_content;
                                }
                            }
                        }
                    }
                    
                    // Extract div text (for newsletter descriptions, custom HTML, etc.)
                    // First try specific divs with common classes
                    if (preg_match_all('/<div[^>]*class=["\'][^"\']*(?:text|description|newsletter|content|custom|widget)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $div_matches)) {
                        foreach ($div_matches[1] as $div_text) {
                            $div_content = strip_tags($div_text);
                            $div_content = html_entity_decode($div_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $div_content = trim($div_content);
                            $div_content = preg_replace('/\s+/', ' ', $div_content);
                            if (!empty($div_content) && strlen($div_content) > 3) {
                                // Split by <br> tags
                                $div_lines = preg_split('/<br[^>]*>/i', $div_content);
                                foreach ($div_lines as $div_line) {
                                    $div_line = trim($div_line);
                                    if (!empty($div_line) && strlen($div_line) > 3) {
                                        $widget_texts[] = $div_line;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Also extract from textwidget and custom-html-widget classes
                    // This is important for custom HTML widgets that contain newsletter signup text
                    if (preg_match_all('/<div[^>]*class=["\'][^"\']*(?:textwidget|custom-html-widget)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $textwidget_matches)) {
                        foreach ($textwidget_matches[1] as $textwidget_content) {
                            // Extract paragraphs from within the widget
                            if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $textwidget_content, $widget_p_matches)) {
                                foreach ($widget_p_matches[1] as $widget_p) {
                                    // Remove icon tags
                                    $widget_p = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $widget_p);
                                    // Remove link tags but keep text
                                    $widget_p = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $widget_p);
                                    // Get all text
                                    $widget_p_text = strip_tags($widget_p);
                                    $widget_p_text = html_entity_decode($widget_p_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                    $widget_p_text = trim($widget_p_text);
                                    $widget_p_text = preg_replace('/\s+/', ' ', $widget_p_text);
                                    if (!empty($widget_p_text) && strlen($widget_p_text) > 3) {
                                        $widget_texts[] = $widget_p_text;
                                    }
                                }
                            }
                            
                            // Also extract any remaining text from divs within the widget
                            if (preg_match_all('/<div[^>]*>(.*?)<\/div>/is', $textwidget_content, $widget_div_matches)) {
                                foreach ($widget_div_matches[1] as $widget_div) {
                                    // Remove icon tags
                                    $widget_div = preg_replace('/<i[^>]*>.*?<\/i>/is', '', $widget_div);
                                    // Remove link tags but keep text
                                    $widget_div = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $widget_div);
                                    // Get all text
                                    $widget_div_text = strip_tags($widget_div);
                                    $widget_div_text = html_entity_decode($widget_div_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                    $widget_div_text = trim($widget_div_text);
                                    $widget_div_text = preg_replace('/\s+/', ' ', $widget_div_text);
                                    if (!empty($widget_div_text) && strlen($widget_div_text) > 5) {
                                        $widget_texts[] = $widget_div_text;
                                    }
                                }
                            }
                            
                            // Extract any remaining text content
                            $text_content = strip_tags($textwidget_content);
                            $text_content = html_entity_decode($text_content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $text_content = preg_replace('/\s+/', ' ', $text_content);
                            // Split by <br> tags
                            $text_lines = preg_split('/<br[^>]*>/i', $text_content);
                            foreach ($text_lines as $text_line) {
                                $text_line = trim($text_line);
                                if (!empty($text_line) && strlen($text_line) > 5) {
                                    $widget_texts[] = $text_line;
                                }
                            }
                        }
                    }
                    
                    // Extract span text
                    if (preg_match_all('/<span[^>]*>(.*?)<\/span>/is', $html, $span_matches)) {
                        foreach ($span_matches[1] as $span_text) {
                            $span_content = strip_tags($span_text);
                            $span_content = trim($span_content);
                            if (!empty($span_content)) {
                                $widget_texts[] = $span_content;
                            }
                        }
                    }
                    
                    // Extract icon classes from HTML (e.g., class="icon-pay", "fab fa-instagram")
                    if (preg_match_all('/class=["\']([^"\']*(?:icon|fa|fab)[^"\']*)["\']/i', $html, $class_matches)) {
                        foreach ($class_matches[1] as $classes) {
                            $class_array = explode(' ', $classes);
                            foreach ($class_array as $class) {
                                $class = trim($class);
                                // Include icon classes (icon-*, fa-*, fab-*)
                                if (!empty($class) && (strpos($class, 'icon') !== false || strpos($class, 'fa-') !== false)) {
                                    $widget_texts[] = $class;
                                }
                            }
                        }
                    }
                    
                    // Extract payment method names from alt text or title
                    if (preg_match_all('/title=["\']([^"\']*(?:paypal|visa|mastercard|amex|american.?express|skrill|bank|transfer|payment)[^"\']*)["\']/i', $html, $title_matches)) {
                        foreach ($title_matches[1] as $title) {
                            $title = trim($title);
                            if (!empty($title)) {
                                $widget_texts[] = $title;
                            }
                        }
                    }
                    
                    // Extract all remaining text content from HTML, preserving structure
                    // Remove script and style tags first
                    $clean_html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
                    $clean_html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $clean_html);
                    
                    // Before doing final extraction, make sure we've captured ALL paragraphs
                    // Sometimes paragraphs might be in nested structures we missed
                    if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $clean_html, $final_p_matches)) {
                        foreach ($final_p_matches[1] as $final_p) {
                            $final_p_clean = preg_replace('/<[^>]+>/', '', $final_p); // Remove all tags
                            $final_p_clean = html_entity_decode($final_p_clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $final_p_clean = trim($final_p_clean);
                            $final_p_clean = preg_replace('/\s+/', ' ', $final_p_clean);
                            if (!empty($final_p_clean) && strlen($final_p_clean) > 5) {
                                $final_p_lower = strtolower($final_p_clean);
                                // Check if this paragraph text is already in our array
                                $already_exists = false;
                                foreach ($widget_texts as $existing) {
                                    if (strtolower($existing) === $final_p_lower) {
                                        $already_exists = true;
                                        break;
                                    }
                                }
                                if (!$already_exists) {
                                    $widget_texts[] = $final_p_clean;
                                }
                            }
                        }
                    }
                    
                    // Extract text from all remaining elements
                    $text = strip_tags($clean_html, '<br><p><span><div>'); // Keep some tags temporarily
                    // Replace <br> with newlines
                    $text = preg_replace('/<br[^>]*>/i', "\n", $text);
                    // Replace <p> tags with newlines
                    $text = preg_replace('/<p[^>]*>/i', "\n", $text);
                    $text = preg_replace('/<\/p>/i', "\n", $text);
                    // Now strip all remaining tags
                    $text = strip_tags($text);
                    // Decode HTML entities
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    // Replace multiple whitespace with single space, but preserve line breaks
                    $text = preg_replace('/[ \t]+/', ' ', $text);
                    // Split by line breaks and clean each line
                    $lines = preg_split('/[\r\n]+/', $text);
                    $extracted_texts_lower = array_map('strtolower', $widget_texts);
                    
                    foreach ($lines as $line) {
                        $line = trim($line);
                        // Only add if it's meaningful text (at least 5 characters for longer text)
                        if (!empty($line) && strlen($line) > 4) {
                            $line_lower = strtolower($line);
                            // Skip if this text was already extracted from structured elements
                            // Be less aggressive with duplicate checking - only skip exact matches or very similar long text
                            $is_duplicate = false;
                            foreach ($extracted_texts_lower as $extracted) {
                                // Skip only if it's an exact match or if both are long and very similar
                                if ($line_lower === $extracted || 
                                    (strlen($line_lower) > 20 && strlen($extracted) > 20 && 
                                     (strpos($extracted, $line_lower) !== false || strpos($line_lower, $extracted) !== false))) {
                                    $is_duplicate = true;
                                    break;
                                }
                            }
                            if (!$is_duplicate) {
                                $widget_texts[] = $line;
                            }
                        }
                    }
                    
                    // Add widget texts to main array, separated by row
                    if (!empty($widget_texts)) {
                        // Add row label
                        if (!empty($text_parts)) {
                            $text_parts[] = ''; // Empty line between rows
                        }
                        $text_parts[] = "Row $row_index (footer-$row_index):";
                        $text_parts = array_merge($text_parts, $widget_texts);
                    }
                }
            }
        }

        // Extract from extracted metadata (logos, menus, etc.)
        if (isset($parsed_data['extracted'])) {
            $extracted = $parsed_data['extracted'];
            
            // Site title and tagline
            if (!empty($extracted['site_title'])) {
                $text_parts[] = $extracted['site_title'];
            }
            if (!empty($extracted['site_tagline'])) {
                $text_parts[] = $extracted['site_tagline'];
            }

            // Logo alt text
            if (isset($extracted['logos']) && is_array($extracted['logos'])) {
                foreach ($extracted['logos'] as $logo) {
                    if (!empty($logo['alt'])) {
                        $text_parts[] = $logo['alt'];
                    }
                }
            }

            // Menu items
            if (isset($extracted['menus']) && is_array($extracted['menus'])) {
                foreach ($extracted['menus'] as $menu) {
                    if (isset($menu['items']) && is_array($menu['items'])) {
                        foreach ($menu['items'] as $item) {
                            if (!empty($item['title'])) {
                                $text_parts[] = $item['title'];
                            }
                        }
                    }
                }
            }

            // Buttons
            if (isset($extracted['buttons']) && is_array($extracted['buttons'])) {
                foreach ($extracted['buttons'] as $button) {
                    if (!empty($button['text'])) {
                        $text_parts[] = $button['text'];
                    }
                }
            }

            // Icons with text
            if (isset($extracted['icons']) && is_array($extracted['icons'])) {
                foreach ($extracted['icons'] as $icon) {
                    if (!empty($icon['text'])) {
                        $text_parts[] = $icon['text'];
                    }
                    if (!empty($icon['title'])) {
                        $text_parts[] = $icon['title'];
                    }
                    // Extract icon class names (e.g., icon-pay)
                    if (!empty($icon['icon'])) {
                        $icon_name = $icon['icon'];
                        // Extract class name from icon (e.g., "fa fa-pay" -> "pay", "icon-pay" -> "icon-pay")
                        if (strpos($icon_name, 'icon-') !== false) {
                            $text_parts[] = $icon_name;
                        } elseif (strpos($icon_name, '-') !== false) {
                            $parts = explode('-', $icon_name);
                            $last_part = end($parts);
                            if (!empty($last_part)) {
                                $text_parts[] = 'icon-' . $last_part;
                            }
                        }
                    }
                }
            }
            
            // Social icons
            if (isset($extracted['social_icons']) && is_array($extracted['social_icons'])) {
                foreach ($extracted['social_icons'] as $social_icon) {
                    if (!empty($social_icon['network'])) {
                        $text_parts[] = $social_icon['network'];
                    }
                    if (!empty($social_icon['icon'])) {
                        $icon_name = $social_icon['icon'];
                        if (strpos($icon_name, 'icon-') !== false) {
                            $text_parts[] = $icon_name;
                        }
                    }
                }
            }
        }

        // Extract from menus
        if (isset($parsed_data['menus']) && is_array($parsed_data['menus'])) {
            foreach ($parsed_data['menus'] as $menu_key => $menu_items) {
                if (is_array($menu_items)) {
                    foreach ($menu_items as $item) {
                        if (isset($item['title']) && !empty($item['title'])) {
                            $text_parts[] = $item['title'];
                        }
                    }
                }
            }
        }

        // Extract footer bottom section (copyright and footer menu)
        // Check for structured footer_bottom data (new format)
        if (isset($parsed_data['footer_bottom']) && is_array($parsed_data['footer_bottom'])) {
            $footer_bottom_texts = [];
            $footer_bottom = $parsed_data['footer_bottom'];
            
            // Extract menu items
            if (isset($footer_bottom['menu_items']) && is_array($footer_bottom['menu_items'])) {
                foreach ($footer_bottom['menu_items'] as $item) {
                    if (!empty($item['title'])) {
                        $footer_bottom_texts[] = $item['title'];
                    }
                    if (!empty($item['url'])) {
                        $footer_bottom_texts[] = $item['url'];
                    }
                }
            }
            
            // Extract text content
            if (isset($footer_bottom['text_content']) && is_array($footer_bottom['text_content'])) {
                foreach ($footer_bottom['text_content'] as $text) {
                    if (!empty($text)) {
                        $footer_bottom_texts[] = $text;
                    }
                }
            }
            
            // Extract links
            if (isset($footer_bottom['links']) && is_array($footer_bottom['links'])) {
                foreach ($footer_bottom['links'] as $link) {
                    if (!empty($link['title'])) {
                        $footer_bottom_texts[] = $link['title'];
                    }
                    if (!empty($link['url'])) {
                        $footer_bottom_texts[] = $link['url'];
                    }
                }
            }
            
            if (!empty($footer_bottom_texts)) {
                if (!empty($text_parts)) {
                    $text_parts[] = '';
                    $text_parts[] = '';
                }
                $text_parts[] = "Footer Bottom:";
                $text_parts = array_merge($text_parts, $footer_bottom_texts);
            }
        }
        
        // Fallback: Check if footer widget area exists (old HTML format)
        if (isset($parsed_data['widgets']['footer']) && !empty($parsed_data['widgets']['footer']['html'])) {
            $footer_bottom_html = $parsed_data['widgets']['footer']['html'];
            $footer_bottom_texts = [];
            
            // Extract copyright text
            if (preg_match_all('/Copyright[^<]*/i', $footer_bottom_html, $copyright_matches)) {
                foreach ($copyright_matches[0] as $copyright) {
                    $copyright = strip_tags($copyright);
                    $copyright = html_entity_decode($copyright, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $copyright = trim($copyright);
                    if (!empty($copyright)) {
                        $footer_bottom_texts[] = $copyright;
                    }
                }
            }
            
            // Extract footer menu links
            if (preg_match_all('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $footer_bottom_html, $footer_menu_matches, PREG_SET_ORDER)) {
                foreach ($footer_menu_matches as $menu_match) {
                    $menu_url = trim($menu_match[1]);
                    $menu_text = strip_tags($menu_match[2]);
                    $menu_text = trim($menu_text);
                    
                    // Convert relative URLs to absolute
                    if (!empty($menu_url) && strpos($menu_url, 'http') !== 0 && strpos($menu_url, 'mailto:') !== 0 && strpos($menu_url, 'tel:') !== 0) {
                        $menu_url = site_url($menu_url);
                    }
                    
                    if (!empty($menu_text)) {
                        $footer_bottom_texts[] = $menu_text;
                    }
                    if (!empty($menu_url)) {
                        $footer_bottom_texts[] = $menu_url;
                    }
                }
            }
            
            if (!empty($footer_bottom_texts)) {
                if (empty($text_parts) || end($text_parts) !== "Footer Bottom:") {
                    if (!empty($text_parts)) {
                        $text_parts[] = '';
                        $text_parts[] = '';
                    }
                    $text_parts[] = "Footer Bottom:";
                }
                $text_parts = array_merge($text_parts, $footer_bottom_texts);
            }
        }
        
        // Extract footer menu from parsed data (if exists separately)
        if (isset($parsed_data['footer_menu']) && is_array($parsed_data['footer_menu'])) {
            $footer_menu_texts = [];
            foreach ($parsed_data['footer_menu'] as $menu_item) {
                if (isset($menu_item['title']) && !empty($menu_item['title'])) {
                    $footer_menu_texts[] = $menu_item['title'];
                }
                if (isset($menu_item['url']) && !empty($menu_item['url'])) {
                    $footer_menu_texts[] = $menu_item['url'];
                }
            }
            
            if (!empty($footer_menu_texts)) {
                if (empty($text_parts) || end($text_parts) !== "Footer Bottom:") {
                    if (!empty($text_parts)) {
                        $text_parts[] = '';
                        $text_parts[] = '';
                    }
                    $text_parts[] = "Footer Bottom:";
                }
                $text_parts = array_merge($text_parts, $footer_menu_texts);
            }
        }
        
        // Extract from copyright field (if exists separately)
        if (isset($parsed_data['copyright']) && !empty($parsed_data['copyright'])) {
            $copyright_text = strip_tags($parsed_data['copyright']);
            $copyright_text = html_entity_decode($copyright_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $copyright_text = trim($copyright_text);
            if (!empty($copyright_text)) {
                if (empty($text_parts) || end($text_parts) !== "Footer Bottom:") {
                    if (!empty($text_parts)) {
                        $text_parts[] = '';
                        $text_parts[] = '';
                    }
                    $text_parts[] = "Footer Bottom:";
                }
                $text_parts[] = $copyright_text;
            }
        }

        // Clean and filter text parts, preserving structure
        $clean_parts = [];
        foreach ($text_parts as $text) {
            $text = trim($text);
            // Decode HTML entities
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Allow empty strings for structure (separators between sections)
            // and non-empty text
            if ($text === '' || !empty($text)) {
                $clean_parts[] = $text;
            }
        }

        // Join with newlines, but collapse multiple consecutive empty lines
        $result = implode("\n", $clean_parts);
        // Collapse 3+ consecutive newlines to 2
        $result = preg_replace('/\n{3,}/', "\n\n", $result);
        
        return trim($result);
    }

    /**
     * Extract text from Elementor section (row-wise, column by column)
     */
    private function extract_text_from_section($section, &$text_parts) {
        // Extract from columns (each column in order)
        if (isset($section['columns']) && is_array($section['columns'])) {
            foreach ($section['columns'] as $column_index => $column) {
                if (isset($column['widgets']) && is_array($column['widgets'])) {
                    foreach ($column['widgets'] as $widget) {
                        $this->extract_text_from_widget($widget, $text_parts);
                    }
                }
            }
        }

        // Extract from direct widgets
        if (isset($section['widgets']) && is_array($section['widgets'])) {
            foreach ($section['widgets'] as $widget) {
                $this->extract_text_from_widget($widget, $text_parts);
            }
        }
    }

    /**
     * Extract text from Elementor widget
     */
    private function extract_text_from_widget($widget, &$text_parts) {
        if (!isset($widget['settings']) || !is_array($widget['settings'])) {
            return;
        }

        $settings = $widget['settings'];
        $widget_type = isset($widget['type']) ? $widget['type'] : '';

        // Heading
        if (!empty($settings['title'])) {
            $text_parts[] = $settings['title'];
        }

        // Text editor - preserve line breaks
        if (!empty($settings['editor'])) {
            $html = $settings['editor'];
            $text = strip_tags($html);
            // Replace multiple whitespace with single space, but preserve line breaks
            $text = preg_replace('/[ \t]+/', ' ', $text);
            // Split by line breaks and add each line
            $lines = preg_split('/[\r\n]+/', $text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $text_parts[] = $line;
                }
            }
        }

        // Button text
        if (!empty($settings['text'])) {
            $text_parts[] = $settings['text'];
        }

        // Image alt text
        if (!empty($settings['alt'])) {
            $text_parts[] = $settings['alt'];
        }

        // Image caption
        if (!empty($settings['caption'])) {
            $text_parts[] = $settings['caption'];
        }

        // Image box title and description
        if (!empty($settings['title_text'])) {
            $text_parts[] = $settings['title_text'];
        }
        if (!empty($settings['description_text'])) {
            $text_parts[] = $settings['description_text'];
        }

        // Icon list items
        if (isset($settings['icon_list']) && is_array($settings['icon_list'])) {
            foreach ($settings['icon_list'] as $item) {
                if (!empty($item['text'])) {
                    $text_parts[] = $item['text'];
                }
                // Extract icon class/name
                if (!empty($item['selected_icon']['value'])) {
                    $icon_name = $item['selected_icon']['value'];
                    if (strpos($icon_name, 'icon-') !== false) {
                        $text_parts[] = $icon_name;
                    }
                }
            }
        }

        // Icon box widget
        if ($widget_type === 'icon-box') {
            if (!empty($settings['selected_icon']['value'])) {
                $icon_name = $settings['selected_icon']['value'];
                if (strpos($icon_name, 'icon-') !== false) {
                    $text_parts[] = $icon_name;
                }
            }
        }

        // Social icons widget
        if ($widget_type === 'social-icons' && isset($settings['social_icon_list']) && is_array($settings['social_icon_list'])) {
            foreach ($settings['social_icon_list'] as $social_icon) {
                if (!empty($social_icon['selected_icon']['value'])) {
                    $icon_name = $social_icon['selected_icon']['value'];
                    if (strpos($icon_name, 'icon-') !== false) {
                        $text_parts[] = $icon_name;
                    }
                }
            }
        }

        // Menu items
        if (isset($settings['menu_items']) && is_array($settings['menu_items'])) {
            foreach ($settings['menu_items'] as $item) {
                if (!empty($item['title'])) {
                    $text_parts[] = $item['title'];
                }
            }
        }

        // Form fields - check for form widgets
        if ($widget_type === 'form' || isset($settings['form_fields'])) {
            // Extract form title/label
            if (!empty($settings['form_title'])) {
                $text_parts[] = $settings['form_title'];
            }
            if (!empty($settings['form_name'])) {
                $text_parts[] = $settings['form_name'];
            }
            
            // Extract form fields
            if (isset($settings['form_fields']) && is_array($settings['form_fields'])) {
                foreach ($settings['form_fields'] as $field) {
                    if (!empty($field['field_label'])) {
                        $text_parts[] = $field['field_label'];
                    }
                    if (!empty($field['placeholder'])) {
                        $text_parts[] = $field['placeholder'];
                    }
                    if (!empty($field['field_title'])) {
                        $text_parts[] = $field['field_title'];
                    }
                }
            }
            
            // Extract submit button text
            if (!empty($settings['submit_button_text'])) {
                $text_parts[] = $settings['submit_button_text'];
            }
            if (!empty($settings['button_text'])) {
                $text_parts[] = $settings['button_text'];
            }
        }

        // Search form placeholder and button
        if ($widget_type === 'search' || $widget_type === 'search-form') {
            if (!empty($settings['placeholder'])) {
                $text_parts[] = $settings['placeholder'];
            }
            if (!empty($settings['button_text'])) {
                $text_parts[] = $settings['button_text'];
            }
        }

        // Accordion/Tabs
        if (isset($settings['tabs']) && is_array($settings['tabs'])) {
            foreach ($settings['tabs'] as $tab) {
                if (!empty($tab['tab_title'])) {
                    $text_parts[] = $tab['tab_title'];
                }
                if (!empty($tab['tab_content'])) {
                    $text = strip_tags($tab['tab_content']);
                    $text = trim($text);
                    if (!empty($text)) {
                        $text_parts[] = $text;
                    }
                }
            }
        }

        // HTML content - extract line by line to preserve structure
        // Also extract form fields, placeholders, labels, etc.
        if (!empty($settings['html'])) {
            $html = $settings['html'];
            
            // Extract form labels
            if (preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $html, $label_matches)) {
                foreach ($label_matches[1] as $label) {
                    $label_text = strip_tags($label);
                    $label_text = trim($label_text);
                    if (!empty($label_text)) {
                        $text_parts[] = $label_text;
                    }
                }
            }
            
            // Extract input placeholders
            if (preg_match_all('/placeholder=["\']([^"\']+)["\']/i', $html, $placeholder_matches)) {
                foreach ($placeholder_matches[1] as $placeholder) {
                    $placeholder = trim($placeholder);
                    if (!empty($placeholder)) {
                        $text_parts[] = $placeholder;
                    }
                }
            }
            
            // Extract button text (submit, button elements)
            if (preg_match_all('/<(button|input)[^>]*type=["\'](submit|button)["\'][^>]*>(.*?)<\/(button|input)>/is', $html, $button_matches)) {
                foreach ($button_matches[3] as $button_text) {
                    $btn_text = strip_tags($button_text);
                    $btn_text = trim($btn_text);
                    if (!empty($btn_text)) {
                        $text_parts[] = $btn_text;
                    }
                }
            }
            // Extract value attribute for submit buttons
            if (preg_match_all('/<input[^>]*type=["\'](submit|button)["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $value_matches)) {
                foreach ($value_matches[2] as $value) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $text_parts[] = $value;
                    }
                }
            }
            
            // Extract icon classes from HTML (e.g., class="icon-pay")
            if (preg_match_all('/class=["\']([^"\']*icon[^"\']*)["\']/i', $html, $class_matches)) {
                foreach ($class_matches[1] as $classes) {
                    $class_array = explode(' ', $classes);
                    foreach ($class_array as $class) {
                        $class = trim($class);
                        if (!empty($class) && strpos($class, 'icon') !== false) {
                            $text_parts[] = $class;
                        }
                    }
                }
            }
            
            // Extract all text content from HTML, preserving line breaks
            $text = strip_tags($html);
            // Replace multiple whitespace with single space, but preserve line breaks
            $text = preg_replace('/[ \t]+/', ' ', $text);
            // Split by line breaks and add each line
            $lines = preg_split('/[\r\n]+/', $text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $text_parts[] = $line;
                }
            }
        }

        // Testimonial content
        if (!empty($settings['content'])) {
            $text = strip_tags($settings['content']);
            $text = trim($text);
            if (!empty($text)) {
                $text_parts[] = $text;
            }
        }
        if (!empty($settings['name'])) {
            $text_parts[] = $settings['name'];
        }
        if (!empty($settings['job'])) {
            $text_parts[] = $settings['job'];
        }

        // Recursively check nested elements
        if (isset($widget['elements']) && is_array($widget['elements'])) {
            foreach ($widget['elements'] as $nested_widget) {
                $this->extract_text_from_widget($nested_widget, $text_parts);
            }
        }
    }

    /**
     * Extract text from Gutenberg block
     */
    private function extract_text_from_block($block, &$text_parts) {
        if (!isset($block['content']) || !is_array($block['content'])) {
            return;
        }

        $content = $block['content'];
        $block_type = isset($block['type']) ? $block['type'] : '';

        // Heading
        if (!empty($content['text'])) {
            $text_parts[] = $content['text'];
        }

        // Paragraph
        if (!empty($content['text']) && $block_type === 'paragraph') {
            $text_parts[] = $content['text'];
        }

        // Image alt and caption
        if (!empty($content['alt'])) {
            $text_parts[] = $content['alt'];
        }
        if (!empty($content['caption'])) {
            $text_parts[] = $content['caption'];
        }

        // Button text
        if (!empty($content['text']) && $block_type === 'button') {
            $text_parts[] = $content['text'];
        }

        // Quote
        if (!empty($content['value'])) {
            $text = strip_tags($content['value']);
            $text = trim($text);
            if (!empty($text)) {
                $text_parts[] = $text;
            }
        }
        if (!empty($content['citation'])) {
            $text_parts[] = $content['citation'];
        }

        // List
        if (!empty($content['values'])) {
            $list_text = strip_tags($content['values']);
            $list_text = trim($list_text);
            if (!empty($list_text)) {
                $text_parts[] = $list_text;
            }
        }

        // HTML content - extract line by line to preserve structure
        if (!empty($content['html'])) {
            $html = $content['html'];
            $text = strip_tags($html);
            // Replace multiple whitespace with single space, but preserve line breaks
            $text = preg_replace('/[ \t]+/', ' ', $text);
            // Split by line breaks and add each line
            $lines = preg_split('/[\r\n]+/', $text);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $text_parts[] = $line;
                }
            }
        }

        // Shortcode rendered text - also extract from HTML
        if (!empty($content['text'])) {
            $text = strip_tags($content['text']);
            $text = trim($text);
            if (!empty($text)) {
                $text_parts[] = $text;
            }
        }
        
        // Extract from rendered HTML (for forms, etc.)
        if (!empty($content['html'])) {
            $html = $content['html'];
            
            // Extract form labels
            if (preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $html, $label_matches)) {
                foreach ($label_matches[1] as $label) {
                    $label_text = strip_tags($label);
                    $label_text = trim($label_text);
                    if (!empty($label_text)) {
                        $text_parts[] = $label_text;
                    }
                }
            }
            
            // Extract input placeholders
            if (preg_match_all('/placeholder=["\']([^"\']+)["\']/i', $html, $placeholder_matches)) {
                foreach ($placeholder_matches[1] as $placeholder) {
                    $placeholder = trim($placeholder);
                    if (!empty($placeholder)) {
                        $text_parts[] = $placeholder;
                    }
                }
            }
            
            // Extract button text
            if (preg_match_all('/<(button|input)[^>]*type=["\'](submit|button)["\'][^>]*>(.*?)<\/(button|input)>/is', $html, $button_matches)) {
                foreach ($button_matches[3] as $button_text) {
                    $btn_text = strip_tags($button_text);
                    $btn_text = trim($btn_text);
                    if (!empty($btn_text)) {
                        $text_parts[] = $btn_text;
                    }
                }
            }
            // Extract value attribute for submit buttons
            if (preg_match_all('/<input[^>]*type=["\'](submit|button)["\'][^>]*value=["\']([^"\']+)["\']/i', $html, $value_matches)) {
                foreach ($value_matches[2] as $value) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $text_parts[] = $value;
                    }
                }
            }
        }

        // Categories, pages, posts, etc.
        if (isset($content['categories']) && is_array($content['categories'])) {
            foreach ($content['categories'] as $cat) {
                if (!empty($cat['name'])) {
                    $text_parts[] = $cat['name'];
                }
            }
        }
        if (isset($content['pages']) && is_array($content['pages'])) {
            foreach ($content['pages'] as $page) {
                if (!empty($page['title'])) {
                    $text_parts[] = $page['title'];
                }
            }
        }
        if (isset($content['posts']) && is_array($content['posts'])) {
            foreach ($content['posts'] as $post) {
                if (!empty($post['title'])) {
                    $text_parts[] = $post['title'];
                }
            }
        }

        // Recursively check nested blocks
        if (isset($block['blocks']) && is_array($block['blocks'])) {
            foreach ($block['blocks'] as $nested_block) {
                $this->extract_text_from_block($nested_block, $text_parts);
            }
        }
    }

    /**
     * Extract WooCommerce products from Elementor widget settings
     */
    protected function extract_woocommerce_products($settings) {
        if (!class_exists('WooCommerce')) {
            return ['error' => 'WooCommerce is not active'];
        }

        $products = [];
        
        // Get products based on widget settings
        $query_type = isset($settings['query_type']) ? $settings['query_type'] : 'products';
        $products_per_page = isset($settings['products_per_page']) ? (int) $settings['products_per_page'] : 4;
        
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $products_per_page,
            'orderby' => isset($settings['orderby']) ? $settings['orderby'] : 'date',
            'order' => isset($settings['order']) ? $settings['order'] : 'DESC',
        ];

        // Handle different query types
        switch ($query_type) {
            case 'products':
            case 'all':
                // Get all products
                break;
                
            case 'featured':
                $args['tax_query'] = [
                    [
                        'taxonomy' => 'product_visibility',
                        'field' => 'name',
                        'terms' => 'featured',
                    ],
                ];
                break;
                
            case 'sale':
                $args['post__in'] = wc_get_product_ids_on_sale();
                break;
                
            case 'category':
                if (isset($settings['product_categories']) && !empty($settings['product_categories'])) {
                    $args['tax_query'] = [
                        [
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => $settings['product_categories'],
                        ],
                    ];
                }
                break;
                
            case 'tags':
                if (isset($settings['product_tags']) && !empty($settings['product_tags'])) {
                    $args['tax_query'] = [
                        [
                            'taxonomy' => 'product_tag',
                            'field' => 'term_id',
                            'terms' => $settings['product_tags'],
                        ],
                    ];
                }
                break;
                
            case 'ids':
                if (isset($settings['product_ids']) && !empty($settings['product_ids'])) {
                    $args['post__in'] = is_array($settings['product_ids']) 
                        ? $settings['product_ids'] 
                        : explode(',', $settings['product_ids']);
                }
                break;
        }

        // Get products
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());
                
                if ($product) {
                    $products[] = [
                        'id' => $product->get_id(),
                        'title' => $product->get_name(),
                        'slug' => $product->get_slug(),
                        'permalink' => $product->get_permalink(),
                        'price' => $product->get_price(),
                        'regular_price' => $product->get_regular_price(),
                        'sale_price' => $product->get_sale_price(),
                        'price_html' => $product->get_price_html(),
                        'image' => [
                            'id' => $product->get_image_id(),
                            'url' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                            'alt' => get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true) ?: $product->get_name(),
                        ],
                        'gallery' => array_map(function($id) {
                            return [
                                'id' => $id,
                                'url' => wp_get_attachment_image_url($id, 'full'),
                            ];
                        }, $product->get_gallery_image_ids()),
                        'short_description' => $product->get_short_description(),
                        'stock_status' => $product->get_stock_status(),
                        'in_stock' => $product->is_in_stock(),
                        'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
                        'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
                        'sku' => $product->get_sku(),
                        'type' => $product->get_type(),
                    ];
                }
            }
            wp_reset_postdata();
        }

        return [
            'query_type' => $query_type,
            'products_per_page' => $products_per_page,
            'products' => $products,
            'total' => count($products),
        ];
    }

    /**
     * Extract single WooCommerce product from Elementor widget settings
     */
    protected function extract_single_product($settings) {
        if (!class_exists('WooCommerce')) {
            return ['error' => 'WooCommerce is not active'];
        }

        $product_id = isset($settings['product_id']) ? (int) $settings['product_id'] : 0;
        
        if (!$product_id) {
            return ['error' => 'No product ID specified'];
        }

        $product = wc_get_product($product_id);
        
        if (!$product) {
            return ['error' => 'Product not found'];
        }

        return [
            'id' => $product->get_id(),
            'title' => $product->get_name(),
            'slug' => $product->get_slug(),
            'permalink' => $product->get_permalink(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price_html' => $product->get_price_html(),
            'image' => [
                'id' => $product->get_image_id(),
                'url' => wp_get_attachment_image_url($product->get_image_id(), 'full'),
                'alt' => get_post_meta($product->get_image_id(), '_wp_attachment_image_alt', true) ?: $product->get_name(),
            ],
            'gallery' => array_map(function($id) {
                return [
                    'id' => $id,
                    'url' => wp_get_attachment_image_url($id, 'full'),
                ];
            }, $product->get_gallery_image_ids()),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'stock_status' => $product->get_stock_status(),
            'in_stock' => $product->is_in_stock(),
            'stock_quantity' => $product->get_stock_quantity(),
            'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']),
            'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
            'sku' => $product->get_sku(),
            'type' => $product->get_type(),
        ];
    }

    /**
     * Extract product categories with full data (image, title, etc.)
     */
    protected function extract_product_categories($settings) {
        $categories = [];
        
        // Get category IDs from settings
        $category_ids = [];
        if (isset($settings['categories_id']) && is_array($settings['categories_id'])) {
            $category_ids = array_map('intval', $settings['categories_id']);
        } elseif (isset($settings['categories']) && is_array($settings['categories'])) {
            $category_ids = array_map('intval', $settings['categories']);
        } elseif (isset($settings['category_ids']) && is_array($settings['category_ids'])) {
            $category_ids = array_map('intval', $settings['category_ids']);
        }

        // If no specific categories, get all product categories
        if (empty($category_ids)) {
            $all_categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ]);
            if (!is_wp_error($all_categories)) {
                $category_ids = array_map(function($cat) {
                    return $cat->term_id;
                }, $all_categories);
            }
        }

        // Get full category data for each category ID
        foreach ($category_ids as $cat_id) {
            $category = get_term($cat_id, 'product_cat');
            
            if (!$category || is_wp_error($category)) {
                continue;
            }

            // Get category image
            $image_id = get_term_meta($cat_id, 'thumbnail_id', true);
            $image_url = '';
            $image_alt = $category->name;
            
            if ($image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'full');
                $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: $category->name;
            }

            // Get category URL
            $category_url = get_term_link($cat_id, 'product_cat');
            if (is_wp_error($category_url)) {
                $category_url = '';
            }

            $categories[] = [
                'id' => $cat_id,
                'title' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'url' => $category_url,
                'count' => $category->count,
                'image' => [
                    'id' => $image_id ? (int) $image_id : null,
                    'url' => $image_url,
                    'alt' => $image_alt,
                ],
                'parent' => $category->parent,
            ];
        }

        return [
            'categories' => $categories,
            'total' => count($categories),
        ];
    }

    /**
     * Extract blog posts with full data (title, image, excerpt, etc.)
     */
    protected function extract_blog_posts($settings) {
        $posts = [];
        
        // Get posts per page from settings (check multiple possible field names dynamically)
        // Priority: limit (kalles-blog) > posts_per_page > posts_count > number > count
        // If none found, use WordPress default posts_per_page setting
        $posts_per_page = null;
        if (isset($settings['limit']) && !empty($settings['limit'])) {
            $posts_per_page = (int) $settings['limit'];
        } elseif (isset($settings['posts_per_page']) && !empty($settings['posts_per_page'])) {
            $posts_per_page = (int) $settings['posts_per_page'];
        } elseif (isset($settings['posts_count']) && !empty($settings['posts_count'])) {
            $posts_per_page = (int) $settings['posts_count'];
        } elseif (isset($settings['number']) && !empty($settings['number'])) {
            $posts_per_page = (int) $settings['number'];
        } elseif (isset($settings['count']) && !empty($settings['count'])) {
            $posts_per_page = (int) $settings['count'];
        }
        
        // If no limit found in settings, use WordPress default posts_per_page option
        if ($posts_per_page === null || $posts_per_page <= 0) {
            $posts_per_page = (int) get_option('posts_per_page', 10);
        }
        
        // Build query arguments - all settings are read dynamically from widget settings
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $posts_per_page,
            'orderby' => isset($settings['orderby']) && !empty($settings['orderby']) 
                        ? $settings['orderby'] 
                        : (isset($settings['order_by']) ? $settings['order_by'] : 'date'),
            'order' => isset($settings['order']) && !empty($settings['order']) 
                      ? $settings['order'] 
                      : (isset($settings['order_dir']) ? $settings['order_dir'] : 'DESC'),
        ];

        // Handle category filter
        if (isset($settings['category_ids']) && !empty($settings['category_ids'])) {
            $args['category__in'] = is_array($settings['category_ids']) 
                ? array_map('intval', $settings['category_ids'])
                : explode(',', $settings['category_ids']);
        } elseif (isset($settings['categories']) && !empty($settings['categories'])) {
            $args['category__in'] = is_array($settings['categories']) 
                ? array_map('intval', $settings['categories'])
                : explode(',', $settings['categories']);
        } elseif (isset($settings['category']) && !empty($settings['category'])) {
            $args['cat'] = is_array($settings['category']) 
                ? array_map('intval', $settings['category'])
                : (int) $settings['category'];
        }

        // Handle tag filter
        if (isset($settings['tag_ids']) && !empty($settings['tag_ids'])) {
            $args['tag__in'] = is_array($settings['tag_ids']) 
                ? array_map('intval', $settings['tag_ids'])
                : explode(',', $settings['tag_ids']);
        } elseif (isset($settings['tags']) && !empty($settings['tags'])) {
            $args['tag__in'] = is_array($settings['tags']) 
                ? array_map('intval', $settings['tags'])
                : explode(',', $settings['tags']);
        }

        // Handle author filter
        if (isset($settings['authors']) && !empty($settings['authors'])) {
            $args['author__in'] = is_array($settings['authors']) 
                ? array_map('intval', $settings['authors'])
                : explode(',', $settings['authors']);
        } elseif (isset($settings['author']) && !empty($settings['author'])) {
            $args['author'] = (int) $settings['author'];
        }

        // Handle specific post IDs
        if (isset($settings['post_ids']) && !empty($settings['post_ids'])) {
            $args['post__in'] = is_array($settings['post_ids']) 
                ? array_map('intval', $settings['post_ids'])
                : explode(',', $settings['post_ids']);
            $args['orderby'] = 'post__in';
        }

        // Handle exclude posts
        if (isset($settings['exclude']) && !empty($settings['exclude'])) {
            $args['post__not_in'] = is_array($settings['exclude']) 
                ? array_map('intval', $settings['exclude'])
                : explode(',', $settings['exclude']);
        }

        // Handle offset
        if (isset($settings['offset']) && !empty($settings['offset'])) {
            $args['offset'] = (int) $settings['offset'];
        }

        // Get posts
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                // Get featured image
                $image_id = get_post_thumbnail_id($post_id);
                $image_url = '';
                $image_alt = get_the_title();
                
                if ($image_id) {
                    $image_url = wp_get_attachment_image_url($image_id, 'full');
                    $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true) ?: get_the_title();
                }

                // Get author info
                $author_id = get_the_author_meta('ID');
                
                // Get categories
                $categories = wp_get_post_categories($post_id, ['fields' => 'names']);
                $category_ids = wp_get_post_categories($post_id, ['fields' => 'ids']);
                
                // Get tags
                $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
                
                // Get excerpt
                $excerpt = get_the_excerpt($post_id);
                if (empty($excerpt)) {
                    $excerpt = wp_trim_words(get_the_content($post_id), 30);
                }

                $posts[] = [
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'slug' => get_post_field('post_name', $post_id),
                    'permalink' => get_permalink($post_id),
                    'excerpt' => $excerpt,
                    'content' => get_the_content(null, false, $post_id),
                    'date' => get_the_date('c', $post_id),
                    'date_formatted' => get_the_date('', $post_id),
                    'modified' => get_the_modified_date('c', $post_id),
                    'author' => [
                        'id' => $author_id,
                        'name' => get_the_author_meta('display_name', $author_id),
                        'url' => get_author_posts_url($author_id),
                    ],
                    'image' => [
                        'id' => $image_id ? (int) $image_id : null,
                        'url' => $image_url,
                        'alt' => $image_alt,
                    ],
                    'categories' => $categories,
                    'category_ids' => $category_ids,
                    'tags' => $tags,
                    'comment_count' => get_comments_number($post_id),
                ];
            }
            wp_reset_postdata();
        }

        // Return posts data along with query settings for reference
        $result = [
            'posts' => $posts,
            'total' => count($posts),
            'found_posts' => $query->found_posts,
            'posts_per_page' => $posts_per_page,
            'query_settings' => [
                'limit' => isset($settings['limit']) ? $settings['limit'] : null,
                'posts_per_page' => isset($settings['posts_per_page']) ? $settings['posts_per_page'] : null,
                'orderby' => $args['orderby'],
                'order' => $args['order'],
            ],
        ];
        
        // Preserve original widget settings (style, layout, etc.) if they exist
        if (isset($settings['style'])) {
            $result['style'] = $settings['style'];
        }
        if (isset($settings['layout_content'])) {
            $result['layout_content'] = $settings['layout_content'];
        }
        if (isset($settings['layout'])) {
            $result['layout'] = $settings['layout'];
        }
        
        return $result;
    }

    /**
     * Get human-readable section position (first, second, third, etc.)
     */
    protected function get_section_position($index) {
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
        
        // For positions beyond 10, return "section " + number
        return 'section-' . ($index + 1);
    }

    /**
     * Extract background image and color from section settings
     */
    protected function extract_section_background($settings) {
        $background = [];
        
        // Check for background image
        if (isset($settings['background_image']['url']) && !empty($settings['background_image']['url'])) {
            $background['image_url'] = $settings['background_image']['url'];
            if (isset($settings['background_image']['id'])) {
                $background['image_id'] = (int) $settings['background_image']['id'];
            }
        } elseif (isset($settings['background_image']['id']) && !empty($settings['background_image']['id'])) {
            $image_id = (int) $settings['background_image']['id'];
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $background['image_url'] = $image_url;
                $background['image_id'] = $image_id;
            }
        } elseif (isset($settings['background']['image']['url']) && !empty($settings['background']['image']['url'])) {
            $background['image_url'] = $settings['background']['image']['url'];
            if (isset($settings['background']['image']['id'])) {
                $background['image_id'] = (int) $settings['background']['image']['id'];
            }
        } elseif (isset($settings['background']['image']['id']) && !empty($settings['background']['image']['id'])) {
            $image_id = (int) $settings['background']['image']['id'];
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $background['image_url'] = $image_url;
                $background['image_id'] = $image_id;
            }
        }
        
        // Check for background color
        if (isset($settings['background_color']) && !empty($settings['background_color'])) {
            $background['color'] = $settings['background_color'];
        } elseif (isset($settings['background']['color']) && !empty($settings['background']['color'])) {
            $background['color'] = $settings['background']['color'];
        } elseif (isset($settings['background_color']['color']) && !empty($settings['background_color']['color'])) {
            $background['color'] = $settings['background_color']['color'];
        }
        
        // Check for background overlay color
        if (isset($settings['background_overlay']['color']) && !empty($settings['background_overlay']['color'])) {
            $background['overlay_color'] = $settings['background_overlay']['color'];
        } elseif (isset($settings['background_overlay_color']) && !empty($settings['background_overlay_color'])) {
            $background['overlay_color'] = $settings['background_overlay_color'];
        }
        
        // Check for background position, size, repeat
        if (isset($settings['background_position']) && !empty($settings['background_position'])) {
            $background['position'] = $settings['background_position'];
        }
        if (isset($settings['background_size']) && !empty($settings['background_size'])) {
            $background['size'] = $settings['background_size'];
        }
        if (isset($settings['background_repeat']) && !empty($settings['background_repeat'])) {
            $background['repeat'] = $settings['background_repeat'];
        }
        if (isset($settings['background_attachment']) && !empty($settings['background_attachment'])) {
            $background['attachment'] = $settings['background_attachment'];
        }
        
        return $background;
    }
}

