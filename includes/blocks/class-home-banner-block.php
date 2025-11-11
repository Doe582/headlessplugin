<?php
/**
 * Home Banner Section Block Registration
 */

if (!defined('ABSPATH')) {
    exit;
}

class RESTBridge_Home_Banner_Block {
    public function __construct() {
        add_action('init', [$this, 'register_block']);
    }

    public function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }

        $dir = plugin_dir_path(__FILE__) . 'home-banner-section/';
        $url = plugin_dir_url(__FILE__) . 'home-banner-section/';

        $script_path = $dir . 'index.js';
        $script_url = $url . 'index.js';

        if (!file_exists($script_path)) {
            error_log('RESTBridge Home Banner Section: index.js not found at ' . $script_path);
            return;
        }

        wp_register_script(
            'restbridge-home-banner-section-editor-script',
            $script_url,
            ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-editor'],
            filemtime($script_path),
            true
        );

        $editor_style_path = $dir . 'editor.css';
        if (file_exists($editor_style_path)) {
            wp_register_style(
                'restbridge-home-banner-section-editor-style',
                $url . 'editor.css',
                ['wp-edit-blocks'],
                filemtime($editor_style_path)
            );
        }

        $style_path = $dir . 'style-index.css';
        if (file_exists($style_path)) {
            wp_register_style(
                'restbridge-home-banner-section-style',
                $url . 'style-index.css',
                [],
                filemtime($style_path)
            );
        }

        register_block_type('restbridge/home-banner-section', [
            'title'           => __('Home Banner Section', 'restbridge'),
            'icon'            => 'cover-image',
            'category'        => 'design',
            'description'     => __('A hero banner section with title, description, button, image, and discount badge', 'restbridge'),
            'keywords'        => ['banner', 'hero', 'homepage', 'section', 'home'],
            'editor_script'   => 'restbridge-home-banner-section-editor-script',
            'editor_style'    => 'restbridge-home-banner-section-editor-style',
            'style'           => 'restbridge-home-banner-section-style',
            'render_callback' => [$this, 'render_block'],
            'attributes' => [
                'imageId' => [
                    'type' => 'number',
                    'default' => 0
                ],
                'imageUrl' => [
                    'type' => 'string',
                    'default' => ''
                ],
                'discountPercent' => [
                    'type' => 'string',
                    'default' => '70'
                ],
                'showDiscountBadge' => [
                    'type' => 'boolean',
                    'default' => true
                ],
                'backgroundColor' => [
                    'type' => 'string',
                    'default' => '#f5f5f0'
                ],
                'align' => [
                    'type' => 'string',
                    'default' => 'full'
                ]
            ],
            'supports' => [
                'html' => false,
                'align' => ['wide', 'full'],
            ],
        ]);
    }

    public function render_block($attributes, $content, $block) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'home-banner-section/render.php';
        return ob_get_clean();
    }
}

new RESTBridge_Home_Banner_Block();
