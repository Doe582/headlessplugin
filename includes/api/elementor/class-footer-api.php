<?php

require_once plugin_dir_path(__FILE__) . 'class-content-parser-trait.php';

class RESTBridge_Footer_API {
    use RESTBridge_Content_Parser;

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/footer', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_footer'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function get_footer($request) {
        $theme_options = $this->prepare_footer_theme_options();

        $response = [
            'function'     => 'getFooter',
            'sections'     => [],
            'footer_style' => [ 
                'background_color' => null,
                'background_image' => null,
            ],
            'theme_options' => $theme_options,
        ];

        return new WP_REST_Response($response, 200);
    }

    private function prepare_footer_theme_options(): array {
        $options = $this->get_footer_options();

        $logo  = $this->build_logo_payload($options['logo'] ?? '');
        $about = isset($options['about']) ? wp_kses_post($options['about']) : '';

        $contact = [
            'address' => isset($options['address']) ? wp_kses_post($options['address']) : '',
            'email'   => isset($options['email']) ? sanitize_email($options['email']) : '',
            'phone'   => isset($options['phone']) ? sanitize_text_field($options['phone']) : '',
        ];

        $quick_links = $this->get_menu_items_for_location('footer_quick');
        $about_links = $this->get_menu_items_for_location('footer_about');

        $fallback = $this->build_template_menu_fallback();
        if (empty($quick_links) && !empty($fallback['footer_quick'])) {
            $quick_links = $fallback['footer_quick'];
        }
        if (empty($about_links) && !empty($fallback['footer_about'])) {
            $about_links = $fallback['footer_about'];
        }

        $copyright = isset($options['copyright']) ? $options['copyright'] : '';
        if (is_string($copyright)) {
            $copyright = str_replace('[styluza_year]', date_i18n('Y'), $copyright);
            $copyright = wp_kses_post($copyright);
        } else {
            $copyright = '';
        }

        // Get social links (same as header API)
        $social_keys = [
            'facebook'  => 'styluza_header_social_facebook',
            'instagram' => 'styluza_header_social_instagram',
            'youtube'   => 'styluza_header_social_youtube',
        ];

        $social_links = [
            'facebook'  => '',
            'instagram' => '',
            'youtube'   => '',
        ];

        foreach ($social_keys as $network => $theme_mod_key) {
            $social_value = get_theme_mod($theme_mod_key, '');
            $social_links[$network] = $social_value ? esc_url_raw($social_value) : '';
        }

        return [
            'column_one' => [
                'title' => __('Footer Column 1', 'twentytwentyfive-child'),
                'logo'  => $logo,
                'about' => $about,
            ],
            'menus' => [
                'title' => __('Footer Menus', 'twentytwentyfive-child'),
                'quick_links' => [
                    'label' => __('Quick Links', 'twentytwentyfive-child'),
                    'items' => $quick_links,
                ],
                'get_to_know_us' => [
                    'label' => __('Get To Know Us', 'twentytwentyfive-child'),
                    'items' => $about_links,
                ],
            ],
            'contact' => [
                'title'   => __('Footer Contact Details', 'twentytwentyfive-child'),
                'address' => $contact['address'],
                'email'   => $contact['email'],
                'phone'   => $contact['phone'],
            ],
            'copyright' => [
                'title' => __('Footer Copyright', 'twentytwentyfive-child'),
                'text'  => $copyright,
            ],
            'social_links' => $social_links,
        ];
    }

    private function get_footer_options(): array {
        if (function_exists('styluza_get_footer_option')) {
            $all = styluza_get_footer_option(null, []);
            if (is_array($all) && !empty($all)) {
                return $all;
            }
        }

        $keys = [
            'logo'      => 'styluza_footer_logo',
            'about'     => 'styluza_footer_about_text',
            'address'   => 'styluza_footer_contact_address',
            'email'     => 'styluza_footer_contact_email',
            'phone'     => 'styluza_footer_contact_phone',
            'copyright' => 'styluza_footer_copyright_text',
        ];

        $options = [];
        foreach ($keys as $key => $mod) {
            $options[$key] = get_theme_mod($mod, '');
        }

        return $options;
    }

    private function build_logo_payload($value): array {
        $logo = [
            'id'  => null,
            'url' => '',
        ];

        if (is_array($value)) {
            $id  = isset($value['id']) ? (int) $value['id'] : 0;
            $url = isset($value['url']) ? (string) $value['url'] : '';

            if ($id > 0) {
                $logo['id'] = $id;
                $resolved   = wp_get_attachment_image_url($id, 'full');
                if ($resolved) {
                    $logo['url'] = $resolved;
                    return $logo;
                }
            }

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $logo['url'] = $url;
                return $logo;
            }

            $value = $id > 0 ? $id : $url;
        }

        if (is_numeric($value) && (int) $value > 0) {
            $logo['id'] = (int) $value;
            $resolved   = wp_get_attachment_image_url($logo['id'], 'full');
            if ($resolved) {
                $logo['url'] = $resolved;
            }
        } elseif (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
            $logo['url'] = $value;
        } elseif (function_exists('get_custom_logo')) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo['id'] = (int) $custom_logo_id;
                $resolved   = wp_get_attachment_image_url($logo['id'], 'full');
                if ($resolved) {
                    $logo['url'] = $resolved;
                }
            }
        }

        return $logo;
    }

    private function get_menu_items_for_location($location): array {
        $menu_id = get_nav_menu_locations()[$location] ?? null;
        if (!$menu_id) {
            return [];
        }

        $items = wp_get_nav_menu_items($menu_id, ['update_post_term_cache' => false]);
        if (empty($items)) {
            return [];
        }

        usort($items, static function ($a, $b) {
            return ($a->menu_order ?? 0) <=> ($b->menu_order ?? 0);
        });

        $mapped = [];
        $charset = get_bloginfo('charset') ?: 'UTF-8';

        foreach ($items as $item) {
            $mapped[] = [
                'title' => html_entity_decode($item->title ?? '', ENT_QUOTES, $charset),
                'url'   => $item->url ?? '',
            ];
        }

        return $mapped;
    }

    private function normalize_menu_items(array $items): array {
        $charset = get_bloginfo('charset') ?: 'UTF-8';
        $sanitized = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $title = isset($item['title']) ? $item['title'] : '';
                $url   = isset($item['url']) ? $item['url'] : '';
            } elseif (is_object($item)) {
                $title = isset($item->title) ? $item->title : '';
                $url   = isset($item->url) ? $item->url : '';
            } else {
                $title = (string) $item;
                $url   = '';
            }

            if ($title === '' && $url === '') {
                continue;
            }

            $sanitized[] = [
                'title' => html_entity_decode((string) $title, ENT_QUOTES, $charset),
                'url'   => (string) $url,
            ];
        }

        return $sanitized;
    }

    private function build_template_menu_fallback(): array {
        $result = [
            'footer_quick' => [],
            'footer_about' => [],
        ];

        $blocks = $this->load_footer_template_blocks();
        if (empty($blocks)) {
            return $result;
        }

        $menus  = $this->collect_navigation_block_menus($blocks);

        if (!empty($menus)) {
            $menus = array_values($menus);
            $result['footer_quick'] = $menus[0] ?? [];
            $result['footer_about'] = $menus[1] ?? [];
        }

        return $result;
    }

    private function collect_navigation_block_menus(array $blocks, array &$menus = []): array {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/navigation') {
                $items = $this->extract_navigation_block_items($block);
                if (!empty($items)) {
                    $menus[] = $items;
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->collect_navigation_block_menus($block['innerBlocks'], $menus);
            }
        }

        return $menus;
    }

    private function extract_navigation_block_items(array $block): array {
        if (!empty($block['attrs']['ref'])) {
            $items = $this->get_navigation_items_from_post((int) $block['attrs']['ref']);
            if (!empty($items)) {
                return $items;
            }
        }

        $items = [];
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                if (($inner['blockName'] ?? '') === 'core/navigation-link') {
                    $items[] = [
                        'title' => $inner['attrs']['label'] ?? '',
                        'url'   => $inner['attrs']['url'] ?? '',
                    ];
                }
            }
        }

        return $this->normalize_menu_items($items);
    }

    private function get_navigation_items_from_post(int $nav_id): array {
        if ($nav_id <= 0) {
            return [];
        }

        $nav_post = get_post($nav_id);
        if (!$nav_post || 'wp_navigation' !== $nav_post->post_type) {
            return [];
        }

        $blocks = parse_blocks($nav_post->post_content);
        $items  = [];

        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'core/navigation-link') {
                $items[] = [
                    'title' => $block['attrs']['label'] ?? '',
                    'url'   => $block['attrs']['url'] ?? '',
                ];
            }

            if (!empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $inner) {
                    if (($inner['blockName'] ?? '') === 'core/navigation-link') {
                        $items[] = [
                            'title' => $inner['attrs']['label'] ?? '',
                            'url'   => $inner['attrs']['url'] ?? '',
                        ];
                    }
                }
            }
        }

        return $this->normalize_menu_items($items);
    }

    private function load_footer_template_blocks(): array {
        if (function_exists('get_block_template')) {
            $template_part = get_block_template(get_stylesheet() . '//footer', 'wp_template_part');
            if ($template_part && !empty($template_part->content)) {
                return parse_blocks($template_part->content);
            }
        }

        $footer_file = get_theme_file_path('parts/footer.html');
        if ($footer_file && file_exists($footer_file)) {
            $raw = file_get_contents($footer_file);
            if ($raw) {
                return parse_blocks($raw);
            }
        }

        return [];
    }
}
