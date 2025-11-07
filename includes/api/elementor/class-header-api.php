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
public function get_header($request) {

    $results = [
        'function'   => 'getHeader',
        'site_title' => get_bloginfo('name'),
        'logo'       => null,
        'menu'       => []
    ];

    // ✅ Logo
    $logo_id = get_theme_mod('custom_logo');
    if ($logo_id) {
        $results['logo'] = wp_get_attachment_image_url($logo_id, 'full');
    }

    // ✅ Try to load header template part
    $template_part = get_block_template( get_stylesheet() . '//header', 'wp_template_part' );

    if (!$template_part) {
        return new WP_REST_Response($results, 200);
    }

    // ✅ Render the header into final HTML (this enables dynamic blocks!)
    $rendered_html = do_blocks( $template_part->content );

    // ✅ Extract all <a href="..."> links from rendered header
    preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $rendered_html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $results['menu'][] = [
            'title' => trim(strip_tags($match[2])),
            'url'   => $match[1],
            'type'  => strlen(trim(strip_tags($match[2]))) ? 'link' : 'icon'
        ];
    }

    return new WP_REST_Response($results, 200);
}


}
