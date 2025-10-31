<?php

class RESTBridge_Tags_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/tags', [
            'methods' => 'GET',
            'callback' => [$this, 'get_tags'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/tags', [
            'methods' => 'POST',
            'callback' => [$this, 'create_tag'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/tags/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_tag'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/tags/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_tag'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/tags/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_tag'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function get_tags(WP_REST_Request $request) {
        $params = $request->get_query_params();
        
        $args = [
            'hide_empty' => isset($params['hide_empty']) ? (bool) $params['hide_empty'] : false,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'name',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'ASC',
        ];

        if (isset($params['search'])) {
            $args['search'] = sanitize_text_field($params['search']);
        }

        $tags = get_tags($args);
        $formatted_tags = array_map([$this, 'format_tag'], $tags);

        return rest_ensure_response([
            'tags' => $formatted_tags,
            'total' => count($formatted_tags),
        ]);
    }

    public function get_tag(WP_REST_Request $request) {
        $tag_id = (int) $request['id'];
        $tag = get_tag($tag_id);

        if (!$tag || is_wp_error($tag)) {
            return new WP_Error('tag_not_found', 'Tag not found', ['status' => 404]);
        }

        return rest_ensure_response($this->format_tag($tag));
    }

    public function create_tag(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $tag_data = [
            'description' => isset($params['description']) ? sanitize_textarea_field($params['description']) : '',
            'slug' => isset($params['slug']) ? sanitize_title($params['slug']) : '',
        ];

        $tag_name = isset($params['name']) ? sanitize_text_field($params['name']) : '';

        $result = wp_insert_term($tag_name, 'post_tag', $tag_data);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($this->format_tag(get_tag($result['term_id'])), 201);
    }

    public function update_tag(WP_REST_Request $request) {
        $tag_id = (int) $request['id'];
        $params = $request->get_json_params();

        $tag = get_tag($tag_id);
        if (!$tag || is_wp_error($tag)) {
            return new WP_Error('tag_not_found', 'Tag not found', ['status' => 404]);
        }

        $update_data = [];
        
        if (isset($params['name'])) {
            $update_data['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['description'])) {
            $update_data['description'] = sanitize_textarea_field($params['description']);
        }
        if (isset($params['slug'])) {
            $update_data['slug'] = sanitize_title($params['slug']);
        }

        $updated = wp_update_term($tag_id, 'post_tag', $update_data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response($this->format_tag(get_tag($tag_id)));
    }

    public function delete_tag(WP_REST_Request $request) {
        $tag_id = (int) $request['id'];

        $result = wp_delete_term($tag_id, 'post_tag');

        if (is_wp_error($result)) {
            return $result;
        }

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete tag', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $tag_id]);
    }

    private function format_tag($tag) {
        return [
            'id' => $tag->term_id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'count' => $tag->count,
            'link' => get_tag_link($tag->term_id),
        ];
    }

    public function check_permission() {
        return current_user_can('manage_categories');
    }
}

