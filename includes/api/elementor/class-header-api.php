<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Header_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/header', [
            'methods' => 'GET',
            'callback' => [$this, 'get_header'],
            'permission_callback' => '__return_true',
        ]);
    }
    private function find_header_background($blocks) {

        // Grab ALL palettes (theme + default)
        $global_settings = wp_get_global_settings();
        $palette = [];
    
        if (!empty($global_settings['color']['palette']['theme'])) {
            $palette = array_merge($palette, $global_settings['color']['palette']['theme']);
        }
        if (!empty($global_settings['color']['palette']['default'])) {
            $palette = array_merge($palette, $global_settings['color']['palette']['default']);
        }
        if (!empty($global_settings['color']['palette'])) {
            $palette = array_merge($palette, $global_settings['color']['palette']);
        }
    
        foreach ($blocks as $block) {
    
            if (($block['blockName'] ?? '') === 'core/group') {
    
                $attrs = $block['attrs'] ?? [];
    
                // ✅ CASE 1: palette-based color like "accent-1"
                if (!empty($attrs['backgroundColor'])) {
                    $slug = $attrs['backgroundColor'];
    
                    foreach ($palette as $color) {
                        if (!empty($color['slug']) && $color['slug'] === $slug) {
                            return [
                                'background_color' => $color['color'],
                                'background_image' => null,
                            ];
                        }
                    }
                }
    
                // ✅ CASE 2: Inline custom hex color
                if (!empty($attrs['style']['color']['background'])) {
                    return [
                        'background_color' => $attrs['style']['color']['background'],
                        'background_image' => null,
                    ];
                }
    
                // ✅ CASE 3:    image
                if (!empty($attrs['style']['background']['backgroundImage'])) {
                    return [
                        'background_color' => null,
                        'background_image' => $attrs['style']['background']['backgroundImage'],
                    ];
                }
            }
    
            if (!empty($block['innerBlocks'])) {
                $found = $this->find_header_background($block['innerBlocks']);
                if ($found) return $found;
            }
        }
    
        return null;
    }
    
    private function load_user_persistent_cart_if_logged_in() {
        if ( ! is_user_logged_in() ) {
            return;
        }
    
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();
        $saved = get_user_meta( $user_id, '_woocommerce_persistent_cart_' . $blog_id, true );
    
        if (! empty($saved['cart']) ) {
            wc_load_cart();
    
            foreach ( $saved['cart'] as $item_key => $item ) {
                WC()->cart->add_to_cart(
                    $item['product_id'],
                    $item['quantity'],
                    $item['variation_id'] ?? 0,
                    $item['variation'] ?? [],
                    $item['cart_item_data'] ?? []
                );
            }
    
            WC()->cart->set_session();
            WC()->cart->calculate_totals();
        }
    }
    

    public function get_header($request) {
        $results = [
            'site_title'    => get_bloginfo('name'),
            'logo'          => null,
            'menu'          => [],
            'cart'          => null,
            'account'       => null,
            'theme_options' => [
                'logo'          => null,
                'shipping_text' => null,
                'social_links'  => [
                    'facebook'  => null,
                    'instagram' => null,
                    'youtube'   => null,
                ],
            ],
        ];

        // Logo
        if ($logo_id = get_theme_mod('custom_logo')) {
            $results['logo'] = wp_get_attachment_image_url($logo_id, 'full');
        }
        $theme_logo = function_exists('styluza_get_header_option')
            ? styluza_get_header_option('logo', '')
            : get_theme_mod('styluza_header_logo', '');

        if ($theme_logo !== '' && $theme_logo !== null) {
            $resolved_logo = null;
            if (is_numeric($theme_logo)) {
                $resolved_logo = wp_get_attachment_image_url((int) $theme_logo, 'full');
            } elseif (is_string($theme_logo) && filter_var($theme_logo, FILTER_VALIDATE_URL)) {
                $resolved_logo = esc_url_raw($theme_logo);
            }

            if (!empty($resolved_logo)) {
                $results['logo'] = $resolved_logo;
                $results['theme_options']['logo'] = $resolved_logo;
            } else {
                $results['theme_options']['logo'] = esc_url_raw($theme_logo);
            }
        } else {
            $results['theme_options']['logo'] = $results['logo'];
        }

        $shipping_text_default = __( 'FREE shipping on US$39.00+', 'twentytwentyfive-child' );
        $shipping_text = function_exists('styluza_get_header_option')
            ? styluza_get_header_option('shipping_text', $shipping_text_default)
            : get_theme_mod('styluza_header_shipping_text', $shipping_text_default);

        if ($shipping_text !== null && $shipping_text !== '') {
            $results['theme_options']['shipping_text'] = wp_kses_post($shipping_text);
        } else {
            $results['theme_options']['shipping_text'] = wp_kses_post($shipping_text_default);
        }

        $social_keys = [
            'facebook'  => 'styluza_header_social_facebook',
            'instagram' => 'styluza_header_social_instagram',
            'youtube'   => 'styluza_header_social_youtube',
        ];

        foreach ($social_keys as $network => $theme_mod_key) {
            $social_value = function_exists('styluza_get_header_option')
                ? styluza_get_header_option($network, '')
                : get_theme_mod($theme_mod_key, '');

            if ($social_value !== null && $social_value !== '') {
                $results['theme_options']['social_links'][$network] = esc_url_raw($social_value);
            } else {
                $results['theme_options']['social_links'][$network] = '';
            }
        }


        // Load header block template
        if (!function_exists('get_block_template')) {
            return new WP_REST_Response($results, 200);
        }
        
        $template_part = get_block_template(get_stylesheet() . '//header', 'wp_template_part');
        if (!$template_part || empty($template_part->content)) {
            return new WP_REST_Response($results, 200);
        }

        // Resolve patterns
        $resolve_patterns = function(string $raw) use (&$resolve_patterns) : string {
            if (!class_exists('WP_Block_Patterns_Registry')) return $raw;
            $registry = WP_Block_Patterns_Registry::get_instance();
            $blocks   = parse_blocks($raw);

            $out      = '';
            foreach ($blocks as $b) {
                if (($b['blockName'] ?? '') === 'core/pattern') {
                    $slug = $b['attrs']['slug'] ?? '';
                    $pat  = $slug ? $registry->get_registered($slug) : null;
                    if (!empty($pat['content'])) {
                        $out .= $resolve_patterns($pat['content']);
                        continue;
                    }
                }
                $out .= serialize_block($b);
            }
            return $out;
        };

        $resolved_content = $resolve_patterns($template_part->content);
        $rendered_html = do_blocks($resolved_content);
        $blocks = parse_blocks($resolved_content);
        $style = $this->find_header_background($blocks);

        $results['header_style'] = [
            'background_color' => $style['background_color'] ?? null,
            'background_image' => $style['background_image'] ?? null,
        ];
        
        // Extract menu links (clean format)
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $rendered_html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $a) {
                $title = trim(wp_strip_all_tags($a[2], true));
                // Skip empty titles (icons) and duplicates
                if ($title && !empty($title)) {
                    $url = $a[1];
                    // Check if already exists
                    $exists = false;
                    foreach ($results['menu'] as $menu_item) {
                        if ($menu_item['url'] === $url) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $results['menu'][] = [
                            'title' => $title,
                            'url'   => $url,
                            'type'  => 'link'
                        ];
                    }
                }
            }
        }

        // Dynamic SVG extraction - find all SVGs and identify them by context
        $icons = $this->extract_all_icons_dynamically($rendered_html);
        
        // Find cart icon dynamically (URL, label, class, or key heuristics)
        $cart_icon = null;
        $cart_url = null;
        foreach ($icons as $icon_key => $icon) {
            $matches_cart = false;
            $url_path = null;

            if (!empty($icon['url'])) {
                $url_path = parse_url($icon['url'], PHP_URL_PATH);
                if ($url_path && preg_match('/\b(cart|basket|checkout|bag|shopping)\b/i', $url_path)) {
                    $matches_cart = true;
                }
            }

            if (!$matches_cart && !empty($icon['title'])) {
                $title_normalized = strtolower($icon['title']);
                if (preg_match('/\b(cart|basket|bag|checkout|shopping)\b/', $title_normalized)) {
                    $matches_cart = true;
                }
            }

            if (!$matches_cart && !empty($icon['parent_classes'])) {
                $class_string = strtolower(implode(' ', (array) $icon['parent_classes']));
                if (preg_match('/\b(cart|basket|bag|checkout|shopping)\b/', $class_string)) {
                    $matches_cart = true;
                }
            }

            if (!$matches_cart && is_string($icon_key)) {
                if (preg_match('/\b(cart|basket|bag|checkout|shopping)\b/i', $icon_key)) {
                    $matches_cart = true;
                }
            }

            if (!$matches_cart && !empty($icon['svg']) && stripos($icon['svg'], 'wc-block-mini-cart__icon') !== false) {
                $matches_cart = true;
            }

            if ($matches_cart) {
                $cart_icon = $icon;
                $cart_url = $icon['url'] ?? null;
                break;
            }
        }
        if (!$cart_icon) {
            foreach ($icons as $icon_key => $icon) {
                if (!empty($icon['svg']) && stripos($icon['svg'], 'wc-block-mini-cart__icon') !== false) {
                    $cart_icon = $icon;
                    $cart_url = $icon['url'] ?? $cart_url;
                    break;
                }
            }
        }
        if (!$cart_icon) {
            if (isset($icons['cart'])) {
                $cart_icon = $icons['cart'];
                $cart_url = $cart_icon['url'] ?? $cart_url;
            } elseif (preg_match('/(<svg[^>]*class=\"[^\"]*wc-block-mini-cart__icon[^\"]*\"[^>]*>.*?<\\/svg>)/is', $rendered_html, $cart_svg_match)) {
                $cart_svg = $cart_svg_match[1];
                $cart_icon = [
                    'svg' => $cart_svg,
                    'svg_hash' => md5($cart_svg),
                    'url' => null,
                    'title' => 'Cart',
                    'parent_classes' => [],
                    'parent_id' => null,
                    'parent_data' => [],
                ];
                $icons['cart'] = $cart_icon;
            }
        }
        
        // Ensure WooCommerce session and cart data are available for counts
        $cart_count = 0;
        $session_cart_items = [];
        $persistent_cart_items = [];

        if (function_exists('WC')) {
            $wc = WC();

            if ($wc && !did_action('woocommerce_init')) {
                do_action('woocommerce_init');
            }

            if ($wc && (empty($wc->session) || !is_object($wc->session))) {
                if (method_exists($wc, 'initialize_session')) {
                    $wc->initialize_session();
                } elseif (class_exists('WC_Session_Handler')) {
                    $wc->session = new WC_Session_Handler();
                    $wc->session->init();
                }
            }

            if ($wc && $wc->session && method_exists($wc->session, 'init') && !$wc->session->has_session()) {
                $wc->session->init();
            }

            if ($wc && empty($wc->cart)) {
                if (function_exists('wc_load_cart')) {
                    wc_load_cart();
                } else {
                    $wc->cart = new WC_Cart();
                }
            }

            if ($wc && $wc->session && method_exists($wc->session, 'get')) {
                $session_cart_items = $wc->session->get('cart');
                if (!is_array($session_cart_items)) {
                    $session_cart_items = [];
                }
            }

            if ($wc && $wc->cart) {
                // Ensure cart data loaded from session
                if (method_exists($wc->cart, 'get_cart') && empty($wc->cart->get_cart())) {
                    $wc->cart->get_cart();
                }
                $cart_count = intval($wc->cart->get_cart_contents_count());
            }

            if (empty($session_cart_items) && $wc && $wc->cart && method_exists($wc->cart, 'get_cart')) {
                $session_cart_items = $wc->cart->get_cart();
            }

            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                if ($user_id) {
                    $persistent_cart = get_user_meta($user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true);
                    if (!empty($persistent_cart['cart']) && is_array($persistent_cart['cart'])) {
                        $persistent_cart_items = $persistent_cart['cart'];
                        if ($wc && $wc->session && empty($session_cart_items)) {
                            $wc->session->set('cart', $persistent_cart_items);
                            $session_cart_items = $persistent_cart_items;
                        }
                    }
                }
            }

            if ($wc && $wc->cart && $wc->cart->is_empty()) {
                if (!empty($session_cart_items)) {
                    $this->restore_cart_from_items($session_cart_items);
                } elseif (!empty($persistent_cart_items)) {
                    $this->restore_cart_from_items($persistent_cart_items);
                }

                if (method_exists($wc->cart, 'get_cart')) {
                    $cart_count = intval($wc->cart->get_cart_contents_count());
                    if ($cart_count <= 0) {
                        $wc->cart->get_cart();
                        $cart_count = intval($wc->cart->get_cart_contents_count());
                    }
                }

                if (method_exists($wc->cart, 'set_session')) {
                    $wc->cart->set_session();
                }
            }
        }

        if ($cart_count <= 0 && !empty($session_cart_items)) {
            $cart_count = $this->count_cart_items_from_session($session_cart_items);
        }

        if ($cart_count <= 0 && !empty($persistent_cart_items)) {
            $cart_count = $this->count_cart_items_from_session($persistent_cart_items);
        }

        // Fallback cart URL
        if (!$cart_url && function_exists('wc_get_cart_url')) {
            $cart_url = wc_get_cart_url();
        }
        
        $cart_svg = $cart_icon['svg'] ?? null;

        $results['cart'] = [
            'svg'   => $cart_svg,
            'count' => $cart_count,
            'url'   => $cart_url,
        ];

        // Find account icon dynamically (by URL pattern)
        $account_icon = null;
        $account_url = null;
        foreach ($icons as $icon_key => $icon) {
            $matches_account = false;
            $url_path = null;

            if (!empty($icon['url'])) {
                $url_path = parse_url($icon['url'], PHP_URL_PATH);
                if ($url_path && preg_match('/\b(my-account|account|profile|login|register|customer)\b/i', $url_path)) {
                    $matches_account = true;
                }
            }

            if (!$matches_account && !empty($icon['title'])) {
                $title_normalized = strtolower($icon['title']);
                if (preg_match('/\b(my account|account|profile|login|log in|sign in|customer|user)\b/', $title_normalized)) {
                    $matches_account = true;
                }
            }

            if (!$matches_account && !empty($icon['parent_classes'])) {
                $class_string = strtolower(implode(' ', (array) $icon['parent_classes']));
                if (preg_match('/\b(account|customer|user|login|profile)\b/', $class_string)) {
                    $matches_account = true;
                }
            }

            if (!$matches_account && is_string($icon_key)) {
                if (preg_match('/\b(account|customer|user|login|profile)\b/i', $icon_key)) {
                    $matches_account = true;
                }
            }

            if ($matches_account) {
                $account_icon = $icon;
                $account_url = $icon['url'] ?? null;
                break;
            }
        }
        
        // Fallback account URL
        $my_account_page = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : null;
        if ($my_account_page) {
            $account_url = $my_account_page;
        } elseif (!$account_url) {
            $account_url = home_url('/my-account/');
        }
        
        $results['account'] = [
            'svg' => $account_icon['svg'] ?? null,
                    'url' => $account_url,
                ];
                
        return new WP_REST_Response($results, 200);
    }
    
    /**
     * Dynamically extract all icons/SVGs from header HTML and identify them by context
     */
    private function extract_all_icons_dynamically($html) {
        $icons = [];
        
        if (empty($html)) {
            return $icons;
        }
        
        // Extract all SVGs with their positions
        preg_match_all('/<svg\b[^>]*>.*?<\/svg>/is', $html, $svg_matches, PREG_OFFSET_CAPTURE);
        
        if (empty($svg_matches[0])) {
            return $icons;
        }
        
        foreach ($svg_matches[0] as $match) {
            $svg_html = $match[0];
            $svg_pos = $match[1];
            
            // Get broader context around SVG (500 chars before and after for better analysis)
            $context_start = max(0, $svg_pos - 500);
            $context_end = min(strlen($html), $svg_pos + strlen($svg_html) + 500);
            $context = substr($html, $context_start, $context_end - $context_start);
            
            $icon_type = null;
            $icon_url = null;
            $icon_title = null;
            
            // Step 1: Extract URL from nearby link (most reliable identifier)
            if (preg_match('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>.*?' . preg_quote($svg_html, '/') . '.*?<\/a>/is', $html, $link_match)) {
                $icon_url = $link_match[1];
            } elseif (preg_match('/' . preg_quote($svg_html, '/') . '.*?<a[^>]*href=["\']([^"\']+)["\']/is', $html, $link_match)) {
                $icon_url = $link_match[1];
            } elseif (preg_match('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>.*?<\/a>/is', $context, $link_match)) {
                $icon_url = $link_match[1];
            }
            
            // Step 2: Extract aria-label/title for natural identification
            if (preg_match('/aria-label=["\']([^"\']+)["\']/i', $context, $label_match)) {
                $icon_title = $label_match[1];
            } elseif (preg_match('/title=["\']([^"\']+)["\']/i', $context, $title_match)) {
                $icon_title = $title_match[1];
            }
            
            // Step 3: Extract parent element classes/attributes for context
            $parent_classes = [];
            $parent_id = null;
            $parent_data_attrs = [];
            
            if (preg_match('/(<[^>]*(?:class|id|data-[^=]*)=["\'][^"\']*["\'][^>]*>)[^<]*' . preg_quote($svg_html, '/') . '/is', $html, $parent_match)) {
                $parent_html = $parent_match[1];
                
                // Extract classes
                if (preg_match('/class=["\']([^"\']+)["\']/i', $parent_html, $class_match)) {
                    $parent_classes = array_filter(explode(' ', $class_match[1]));
                }
                
                // Extract ID
                if (preg_match('/id=["\']([^"\']+)["\']/i', $parent_html, $id_match)) {
                    $parent_id = $id_match[1];
                }
                
                // Extract data attributes
                if (preg_match_all('/data-([^=]+)=["\']([^"\']+)["\']/i', $parent_html, $data_matches, PREG_SET_ORDER)) {
                    foreach ($data_matches as $data_match) {
                        $parent_data_attrs[$data_match[1]] = $data_match[2];
                    }
                }
            }
            
            // Generate dynamic key from available data (URL path, title, or position)
            $key = null;
            if ($icon_url) {
                $url_path = parse_url($icon_url, PHP_URL_PATH);
                $url_path = trim($url_path, '/');
                if ($url_path) {
                    // Use last segment of URL path as identifier
                    $path_parts = explode('/', $url_path);
                    $key = end($path_parts);
                }
            }
            
            // Fallback to title if no URL key
            if (!$key && $icon_title) {
                $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $icon_title));
            }
            
            // Final fallback to position
            if (!$key) {
                $key = 'icon_' . $svg_pos;
            }
            
            // Avoid duplicates (same SVG content)
            $svg_hash = md5($svg_html);
            $is_duplicate = false;
            foreach ($icons as $existing_icon) {
                if (isset($existing_icon['svg_hash']) && $existing_icon['svg_hash'] === $svg_hash) {
                    $is_duplicate = true;
                    break;
                }
            }
            
            if (!$is_duplicate) {
                $icon_data = [
                    'svg' => $svg_html,
                    'svg_hash' => $svg_hash,
                    'url' => $icon_url,
                    'title' => $icon_title,
                    'parent_classes' => $parent_classes,
                    'parent_id' => $parent_id,
                    'parent_data' => $parent_data_attrs,
                ];
                
                // Use dynamic key (from URL, title, or position)
                $icons[$key] = $icon_data;
            }
        }
        
        
        
        return $icons;
    }

    /**
     * Count cart quantities from a WooCommerce session or persistent cart array.
     *
     * @param array $cart_items
     * @return int
     */
    private function count_cart_items_from_session($cart_items) {
        if (empty($cart_items) || !is_array($cart_items)) {
            return 0;
        }

        $count = 0;
        foreach ($cart_items as $cart_item) {
            if (is_array($cart_item) && isset($cart_item['quantity'])) {
                $count += max(0, intval($cart_item['quantity']));
            }
        }

        return $count;
    }

    /**
     * Restore WooCommerce cart object from raw session/persistent cart array.
     *
     * @param array $cart_items
     * @return void
     */
    private function restore_cart_from_items($cart_items) {
        if (empty($cart_items) || !is_array($cart_items)) {
            return;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        foreach ($cart_items as $cart_item) {
            $product_id   = isset($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
            $variation_id = isset($cart_item['variation_id']) ? intval($cart_item['variation_id']) : 0;
            $quantity     = isset($cart_item['quantity']) ? intval($cart_item['quantity']) : 0;
            $variation    = isset($cart_item['variation']) && is_array($cart_item['variation']) ? $cart_item['variation'] : [];

            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            try {
                if ($variation_id > 0) {
                    WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
                } else {
                    WC()->cart->add_to_cart($product_id, $quantity);
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        if (method_exists(WC()->cart, 'calculate_totals')) {
            WC()->cart->calculate_totals();
        }
    }

}


