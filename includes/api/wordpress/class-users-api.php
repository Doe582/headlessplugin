<?php

class RESTBridge_Users_API {

    public function register_routes() {
        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users', [
            'methods' => 'GET',
            'callback' => [$this, 'get_users'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users', [
            'methods' => 'POST',
            'callback' => [$this, 'create_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/(?P<id>\\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/(?P<id>\\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/(?P<id>\\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_user'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/me', [
            'methods' => 'GET',
            'callback' => [$this, 'get_current_user'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/login', [
            'methods' => 'POST',
            'callback' => [$this, 'simple_login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users', [
            'methods' => 'GET',
            'callback' => [$this, 'get_users'],
            'permission_callback' => [$this, 'check_simple_token'],
        ]);

    }

    public function get_users(WP_REST_Request $request) {
        $params = $request->get_query_params();
        
        $args = [
            'number' => isset($params['per_page']) ? (int) $params['per_page'] : 10,
            'offset' => isset($params['page']) ? ((int) $params['page'] - 1) * (int) $params['per_page'] : 0,
            'orderby' => isset($params['orderby']) ? sanitize_text_field($params['orderby']) : 'registered',
            'order' => isset($params['order']) ? sanitize_text_field($params['order']) : 'DESC',
        ];

        if (isset($params['search'])) {
            $args['search'] = '*' . sanitize_text_field($params['search']) . '*';
        }

        if (isset($params['role'])) {
            $args['role'] = sanitize_text_field($params['role']);
        }

        $users = get_users($args);
        $formatted_users = array_map([$this, 'format_user'], $users);

        return rest_ensure_response([
            'users' => $formatted_users,
            'total' => count_users()['total_users'],
        ]);
    }

    public function get_user(WP_REST_Request $request) {
        $user_id = (int) $request['id'];
        $user = get_user_by('id', $user_id);

        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
        }

        return rest_ensure_response($this->format_user($user));
    }

    public function get_current_user() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return new WP_Error('not_authenticated', 'User not authenticated', ['status' => 401]);
        }

        $user = get_user_by('id', $user_id);
        return rest_ensure_response($this->format_user($user));
    }

    public function create_user(WP_REST_Request $request) {
        $params = $request->get_json_params();

        $user_data = [
            'user_login' => isset($params['username']) ? sanitize_user($params['username']) : '',
            'user_email' => isset($params['email']) ? sanitize_email($params['email']) : '',
            'user_pass' => isset($params['password']) ? $params['password'] : wp_generate_password(),
            'role' => isset($params['role']) ? sanitize_text_field($params['role']) : 'subscriber',
        ];

        if (isset($params['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($params['display_name']);
        }

        if (isset($params['first_name'])) {
            $user_data['first_name'] = sanitize_text_field($params['first_name']);
        }

        if (isset($params['last_name'])) {
            $user_data['last_name'] = sanitize_text_field($params['last_name']);
        }

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        return rest_ensure_response($this->format_user(get_user_by('id', $user_id)), 201);
    }

    public function update_user(WP_REST_Request $request) {
        $user_id = (int) $request['id'];
        $params = $request->get_json_params();

        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
        }

        $user_data = ['ID' => $user_id];
        
        if (isset($params['email'])) {
            $user_data['user_email'] = sanitize_email($params['email']);
        }
        if (isset($params['password'])) {
            $user_data['user_pass'] = $params['password'];
        }
        if (isset($params['role'])) {
            $user_data['role'] = sanitize_text_field($params['role']);
        }
        if (isset($params['display_name'])) {
            $user_data['display_name'] = sanitize_text_field($params['display_name']);
        }
        if (isset($params['first_name'])) {
            $user_data['first_name'] = sanitize_text_field($params['first_name']);
        }
        if (isset($params['last_name'])) {
            $user_data['last_name'] = sanitize_text_field($params['last_name']);
        }

        $updated = wp_update_user($user_data);

        if (is_wp_error($updated)) {
            return $updated;
        }

        return rest_ensure_response($this->format_user(get_user_by('id', $user_id)));
    }

    public function delete_user(WP_REST_Request $request) {
        $user_id = (int) $request['id'];
        $reassign = isset($request['reassign']) ? (int) $request['reassign'] : null;

        require_once(ABSPATH . 'wp-admin/includes/user.php');
        $result = wp_delete_user($user_id, $reassign);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete user', ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $user_id]);
    }

    private function format_user($user) {
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'first_name' => get_user_meta($user->ID, 'first_name', true),
            'last_name' => get_user_meta($user->ID, 'last_name', true),
            'registered' => $user->user_registered,
            'roles' => $user->roles,
            'avatar' => get_avatar_url($user->ID),
        ];
    }

    public function check_permission() {
        return current_user_can('manage_options');
    }
    
    public function simple_login(WP_REST_Request $request) {
    $params = $request->get_json_params();

    if (!isset($params['username']) || !isset($params['password'])) {
        return new WP_Error('missing_fields', 'Username and password required', ['status' => 400]);
    }

    $user = wp_authenticate($params['username'], $params['password']);

    if (is_wp_error($user)) {
        return new WP_Error('invalid_login', 'Invalid username or password', ['status' => 401]);
    }

    // create a simple random token
    $token = bin2hex(random_bytes(20)); // Example: 3f5ab2c8ea…

    update_user_meta($user->ID, '_api_token', $token); // store token

    return [
        'token' => $token,
        'user_id' => $user->ID,
        'email' => $user->user_email,
        'display_name' => $user->display_name,
    ];
}

public function check_simple_token(WP_REST_Request $request) {
    $auth = $request->get_header('authorization'); // Bearer token
    if (!$auth) return false;

    $token = trim(str_replace('Bearer', '', $auth));

    $users = get_users([
        'meta_key' => '_api_token',
        'meta_value' => $token,
        'number' => 1
    ]);

    return !empty($users);
}



}


