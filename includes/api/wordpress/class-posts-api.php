<?php

class RESTBridge_Posts_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts', [
            'methods' => 'GET',
            'callback' => [$this, 'get_posts'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts', [
            'methods' => 'POST',
            'callback' => [$this, 'create_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_post'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/posts/(?P<id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_post'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_posts(WP_REST_Request $request) {
        $params = $request->get_query_params();
        
        $args = [
            'post_type' => 'post',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'publish',
            'posts_per_page' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
            'paged' => isset($params['page']) ? (int) $params['page'] : 1,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'date',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
        ];

        if (isset($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }

        if (isset($params['category'])) {
            $args['cat'] = (int) $params['category'];
        }

        $query = new WP_Query($args);
        $posts = [];

        while ($query->have_posts()) {
            $query->the_post();
            $posts[] = $this->format_post(get_post());
        }
        wp_reset_postdata();

        return rest_ensure_response([
            'posts' => $posts,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
        ]);
    }

    public function get_post(WP_REST_Request $request) {
        $post_id = (int) $request['id'];
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        return rest_ensure_response($this->format_post($post));
    }

    public function create_post(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $post_data = [
            'post_title' => isset($params['title']) ? sanitize_text_field($params['title']) : '',
            'post_content' => isset($params['content']) ? wp_kses_post($params['content']) : '',
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
            'post_type' => 'post',
        ];

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return rest_ensure_response($this->format_post(get_post($post_id)), 201);
    }

    public function update_post(WP_REST_Request $request) {
        $post_id = (int) $request['id'];
        $params = $request->get_json_params();

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        $post_data = ['ID' => $post_id];
        
        if (isset($params['title'])) {
            $post_data['post_title'] = sanitize_text_field($params['title']);
        }
        if (isset($params['content'])) {
            $post_data['post_content'] = wp_kses_post($params['content']);
        }
        if (isset($params['status'])) {
            $post_data['post_status'] = sanitize_text_field($params['status']);
        }

        $updated = wp_update_post($post_data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response($this->format_post(get_post($post_id)));
    }

    public function delete_post(WP_REST_Request $request) {
        $post_id = (int) $request['id'];
        $force = isset($request['force']) && $request['force'];

        $result = wp_delete_post($post_id, $force);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $post_id]);
    }

    private function format_post($post) {
        return [
            'id' => $post->ID,
            'title' => get_the_title($post->ID),
            'content' => apply_filters('the_content', $post->post_content),
            'excerpt' => get_the_excerpt($post->ID),
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'status' => $post->post_status,
            'author' => $post->post_author,
            'slug' => $post->post_name,
            'permalink' => get_permalink($post->ID),
            'categories' => wp_get_post_categories($post->ID),
            'tags' => wp_get_post_tags($post->ID, ['fields' => 'ids']),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            'meta' => get_post_meta($post->ID),
        ];
    }

    public function check_permission() {
        return current_user_can('edit_posts');
    }
}

