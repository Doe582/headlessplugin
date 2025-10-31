<?php

class RESTBridge_Pages_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_pages'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages', [
            'methods' => 'POST',
            'callback' => [$this, 'create_page'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/(?P<id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_page'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/pages/(?P<id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_page'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_pages(WP_REST_Request $request) {
        $params = $request->get_query_params();
        
        $args = [
            'post_type' => 'page',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
            'paged' => isset($params['page']) ? (int) $params['page'] : 1,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'menu_order',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
        ];

        if (isset($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        if (isset($params['parent'])) {
            $args['post_parent'] = (int) $params['parent'];
        }

        $query = new WP_Query($args);
        $pages = [];

        while ($query->have_posts()) {
            $query->the_post();
            $pages[] = $this->format_page(get_post());
        }
        wp_reset_postdata();

        return rest_ensure_response([
            'pages' => $pages,
            'total' => $query->found_posts,
            'pages_count' => $query->max_num_pages,
        ]);
    }

    public function get_page(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $page = get_post($page_id);

        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        return rest_ensure_response($this->format_page($page));
    }

    public function create_page(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $page_data = [
            'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : '',
            'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : '',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
            'post_type' => 'page',
        ];

        if (isset($params['parent'])) {
            $page_data['post_parent'] = (int) $params['parent'];
        }

        $page_id = wp_insert_post($page_data);

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        return rest_ensure_response($this->format_page(get_post($page_id)), 201);
    }

    public function update_page(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $params = $request->get_json_params();

        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return new WP_Error('page_not_found', 'Page not found', ['status' => 404]);
        }

        $page_data = ['ID' => $page_id];
        
        if (isset($params['title'])) {
            $page_data['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $page_data['post_content'] = wp_kses_post($params['content']);
        }
        if (isset($params['status'])) {
            $page_data['post_status'] = sanitize_text_field($params['status']);
        }
        if (isset($params['parent'])) {
            $page_data['post_parent'] = (int) $params['parent'];
        }

        $updated = wp_update_post($page_data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response($this->format_page(get_post($page_id)));
    }

    public function delete_page(WP_REST_Request $request) {
        $page_id = (int) $request['id'];
        $force = isset($request['force']) && $request['force'];

        $result = wp_delete_post($page_id, $force);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete page', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $page_id]);
    }

    private function format_page($page) {
        return [
            'id' => $page->ID,
            'title' => get_the_title($page->ID),
            'content' => apply_filters('the_content', $page->post_content),
            'excerpt' => get_the_excerpt($page->ID),
            'date' => $page->post_date,
            'modified' => $page->post_modified,
            'status' => $page->post_status,
            'author' => $page->post_author,
            'slug' => $page->post_name,
            'permalink' => get_permalink($page->ID),
            'parent' => $page->post_parent,
            'menu_order' => $page->menu_order,
            'featured_image' => get_the_post_thumbnail_url($page->ID, 'full'),
            'meta' => get_post_meta($page->ID),
        ];
    }

    public function check_permission() {
        return current_user_can('edit_pages');
    }
}

