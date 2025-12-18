<?php

class RESTBridge_Users_API {

    public function register_routes() {

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/login', [
            'methods'  => 'POST',
            'callback' => [$this, 'simple_login'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/logout', [
            'methods'  => 'POST',
            'callback' => [$this, 'simple_logout'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/me', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_current_user'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_users'],
            'permission_callback' => [$this, 'permission_users_list'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_user'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/(?P<id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_user'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'delete_user'],
            'permission_callback' => [$this, 'check_admin'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/reset-password', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_password_reset_email'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/update-password', [
            'methods'  => 'POST',
            'callback' => [$this, 'update_password'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/third-party-login', [
            'methods'  => 'POST',
            'callback' => [$this, 'google_auth'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/auth/status', [
            'methods'  => 'GET',
            'callback' => [$this, 'check_auth_status'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/users/token', [
            'methods' => 'POST',
            'callback' => [$this, 'generate_user_token'],
            'permission_callback' => '__return_true',
        ]);

    }

    private function extract_bearer_token(WP_REST_Request $request) {

        // 1. WordPress REST API (preferred)
        $auth = $request->get_header('authorization');

        // 2. Standard PHP server var
        if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }

        // 3. FastCGI / Nginx
        if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // 4. Apache fallback
        if (!$auth && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $auth = $headers['Authorization'];
            }
        }

        // 5. Custom header fallback (frontend safety)
        if (!$auth && isset($_SERVER['HTTP_X_BEARER_TOKEN'])) {
            return sanitize_text_field($_SERVER['HTTP_X_BEARER_TOKEN']);
        }

        // 6. Extract Bearer
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $matches)) {
            return trim($matches[1]);
        }

        // 7. Query param fallback (last resort)
        $param = $request->get_param('token');
        if (!empty($param)) {
            return sanitize_text_field($param);
        }

        return '';
    }


    private function get_user_from_token(WP_REST_Request $request) {

        $token = $this->extract_bearer_token($request);
        if (!$token) return 0;

        $users = get_users([
            'meta_key' => '_api_tokens',
            'number'   => 200,
        ]);

        foreach ($users as $user) {
            $tokens = get_user_meta($user->ID, '_api_tokens', true);
            if (!is_array($tokens)) continue;

            foreach ($tokens as $t) {
                if (isset($t['token']) && $t['token'] === $token) {
                    return (int) $user->ID;
                }
            }
        }

        return 0;
    }


    public function check_simple_token(WP_REST_Request $request) {
        return (bool) $this->get_user_from_token($request);
    }

    public function check_admin() {
        return current_user_can('manage_options');
    }

    public function permission_users_list(WP_REST_Request $request) {
        if (current_user_can('manage_options')) return true;
        return $this->check_simple_token($request);
    }

    /* ===============================
     * LOGIN
     * =============================== */

   public function simple_login(WP_REST_Request $request) {

    $p = $request->get_json_params();

    if (empty($p['username']) || empty($p['password'])) {
        return new WP_Error('missing', 'Username & password required', ['status' => 400]);
    }

    $user = wp_signon([
        'user_login'    => sanitize_text_field($p['username']),
        'user_password' => $p['password'],
    ], is_ssl());

    if (is_wp_error($user)) {
        return new WP_Error('invalid', 'Invalid credentials', ['status' => 401]);
    }

    // 🔹 Set WP session
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    $device = sanitize_text_field($p['device'] ?? 'web');

    // 🔹 Handle tokens
    $tokens = get_user_meta($user->ID, '_api_tokens', true);
    $tokens = $this->normalize_tokens($tokens);

    // Remove old token for same device
    $tokens = array_filter($tokens, fn($t) => ($t['device'] ?? '') !== $device);

    // Generate ONE plain token
    $token = bin2hex(random_bytes(32));

    $tokens[] = [
        'token'    => $token,
        'device'   => $device,
        'provider' => 'password',
        'created'  => time(),
    ];

    update_user_meta($user->ID, '_api_tokens', array_values($tokens));

    return rest_ensure_response([
        'success' => true,
        'token'   => $token,
        'type'    => 'Bearer',
        'user'    => $this->format_user($user),
    ]);
}

    /* ===============================
     * LOGOUT
     * =============================== */

   public function simple_logout(WP_REST_Request $request) {

    // 🔹 Extract Bearer token
    $token = $this->extract_bearer_token($request);
    if (!$token) {
        return new WP_Error(
            'missing_token',
            'Authorization token missing',
            ['status' => 401]
        );
    }

    // 🔹 Resolve user from token
    $user_id = $this->get_user_from_token($request);
    if (!$user_id) {
        return new WP_Error(
            'invalid_token',
            'Invalid token',
            ['status' => 401]
        );
    }

    // 🔹 Load & normalize tokens
    $tokens = get_user_meta($user_id, '_api_tokens', true);
    $tokens = $this->normalize_tokens($tokens);

    // 🔹 Remove ONLY this token
    $tokens = array_values(array_filter($tokens, function ($t) use ($token) {
        return isset($t['token']) && $t['token'] !== $token;
    }));

    update_user_meta($user_id, '_api_tokens', $tokens);

    // 🔹 Logout WP session (cookies)
    wp_logout();

    return rest_ensure_response([
        'success' => true,
        'message' => 'Logged out successfully',
    ]);
}



    /* ===============================
     * USERS
     * =============================== */

    public function get_current_user() {
        $id = get_current_user_id();
        if (!$id) return new WP_Error('unauthorized', 'Not logged in', ['status'=>401]);
        return $this->format_user(get_user_by('id',$id));
    }

    public function get_users(WP_REST_Request $request) {
        $users = get_users(['number'=>20]);
        return array_map([$this,'format_user'],$users);
    }

    public function create_user(WP_REST_Request $request) {
        $p = $request->get_json_params();

        $id = wp_insert_user([
            'user_login' => sanitize_user($p['username']),
            'user_email' => sanitize_email($p['email']),
            'user_pass'  => $p['password'],
            'role'       => 'subscriber',
        ]);

        if (is_wp_error($id)) return $id;

        return $this->format_user(get_user_by('id',$id));
    }

    public function update_user(WP_REST_Request $request) {
        $id = (int)$request['id'];
        wp_update_user(['ID'=>$id,'user_email'=>sanitize_email($request['email'])]);
        return $this->format_user(get_user_by('id',$id));
    }

    public function delete_user(WP_REST_Request $request) {
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user((int)$request['id']);
        return ['deleted'=>true];
    }

    private function format_user($user) {
        return [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => $user->roles,
            'avatar' => get_avatar_url($user->ID),
        ];
    }

    /* ===============================
     * PASSWORD RESET
     * =============================== */

    public function send_password_reset_email(WP_REST_Request $request) {
        $email = sanitize_email( $request['email'] );

	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
	    return new WP_Error(
		'not_found',
		'User not found',
		[ 'status' => 404 ]
	    );
	}

	$token = wp_generate_uuid4();

	update_user_meta( $user->ID, 'reset_password_token', $token );

	$link = "https://main.d2kswwhxcty0zs.amplifyapp.com/reset-password?token={$token}";

	wp_mail(
	    $email,
	    'Confirm Update',
	    "Click here: {$link}"
	);

	return [
	    'success' => true,
	    'message' => 'Email sent',
	];

    }

    public function update_password( $request ) {

    $token    = sanitize_text_field( $request['token'] );
    $password = $request['password'];

    $users = get_users( [
        'meta_key'   => 'reset_password_token',
        'meta_value' => $token,
        'number'     => 1,
    ] );

    if ( empty( $users ) ) {
        return new WP_Error(
            'invalid',
            'Bad token',
            [ 'status' => 400 ]
        );
    }

    $user_id = $users[0]->ID;

    wp_set_password( $password, $user_id );

    delete_user_meta( $user_id, 'reset_password_token' );

    return [
        'success' => true,
        'message' => 'Password updated!',
    ];
}

    public function google_auth(WP_REST_Request $request) {

        $params = $request->get_json_params();

        $email  = sanitize_email($params['email'] ?? '');
        $name   = sanitize_text_field($params['name'] ?? '');
        $avatar = esc_url_raw($params['avatar'] ?? '');
        $device = sanitize_text_field($params['device'] ?? 'google');

        if (empty($email)) {
            return new WP_Error('missing_email', 'Email is required', ['status' => 400]);
        }

        $is_new_user = false;

        // 🔹 Find or create user
        $user = get_user_by('email', $email);

        if (!$user) {
            $is_new_user = true;

            $username = sanitize_user(current(explode('@', $email)));

            if (username_exists($username)) {
                $username .= '_' . wp_generate_password(4, false);
            }

            $user_id = wp_insert_user([
                'user_login'   => $username,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(24, true),
                'display_name' => $name ?: $username,
                'role'         => 'customer',
            ]);

            if (is_wp_error($user_id)) {
                return $user_id;
            }

            $user = get_user_by('id', $user_id);

            if ($avatar) {
                update_user_meta($user->ID, 'google_avatar', $avatar);
            }

            update_user_meta($user->ID, '_signup_provider', 'google');
        }

        // 🔹 Set WP session
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        // 🔹 Handle tokens
        $tokens = get_user_meta($user->ID, '_api_tokens', true);
        $tokens = $this->normalize_tokens($tokens);

        // Remove old token for same device
        $tokens = array_filter($tokens, fn($t) => ($t['device'] ?? '') !== $device);

        // Generate ONE plain token
        $token = bin2hex(random_bytes(32));

        $tokens[] = [
            'token'    => $token,
            'device'   => $device,
            'provider' => 'google',
            'created'  => time(),
        ];

        update_user_meta($user->ID, '_api_tokens', array_values($tokens));

        return rest_ensure_response([
            'success' => true,
            'token'   => $token,
            'type'    => 'Bearer',
            'is_new'  => $is_new_user,
            'user'    => [
                'id'           => $user->ID,
                'email'        => $user->user_email,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
                'avatar'       => $avatar ?: get_avatar_url($user->ID),
            ],
        ]);
    }
    
    private function normalize_tokens($tokens) {
        if (!is_array($tokens)) {
            return [];
        }

        $flat = [];

        foreach ($tokens as $t) {
            // Handle nested arrays
            if (is_array($t) && isset($t[0])) {
                foreach ($t as $inner) {
                    if (is_array($inner)) {
                        $flat[] = $inner;
                    }
                }
            } elseif (is_array($t)) {
                $flat[] = $t;
            }
        }

        return $flat;
    }


    public function check_auth_status(WP_REST_Request $request) {

        // Cookie-based login (WordPress / WooCommerce)
        $user_id = get_current_user_id();

        // Token-based login (headless)
        if (!$user_id) {
            $user_id = $this->get_user_from_token($request);
        }

        // Not logged in
        if (!$user_id) {
            return rest_ensure_response([
                'logged_in' => false,
                'user'      => null,
            ]);
        }

        // Logged in
        $user = get_user_by('id', $user_id);

        return rest_ensure_response([
            'logged_in' => true,
            'user' => [
                'id'           => $user->ID,
                'email'        => $user->user_email,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'roles'        => $user->roles,
                'avatar'       => get_avatar_url($user->ID),
            ],
        ]);
    }

    public function generate_user_token(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $username = isset($params['username']) ? sanitize_text_field($params['username']) : sanitize_text_field($request->get_param('username'));
        $password = isset($params['password']) ? $params['password'] : $request->get_param('password');

        if (empty($username) || empty($password)) {
            return new WP_Error('missing_credentials', 'Username and password are required', ['status' => 400]);
        }

        if (!function_exists('wp_authenticate')) {
            require_once ABSPATH . 'wp-includes/pluggable.php';
        }

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Invalid username or password', ['status' => 401]);
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            return new WP_Error('token_generation_failed', 'Unable to generate token', ['status' => 500]);
        }

        // tokens
        $tokens = get_user_meta($user->ID, '_api_tokens', true);
        $tokens = is_array($tokens) ? $tokens : [];

        $tokens[] = [
            'token'  => $token,
            'device' => 'api',
        ];

        update_user_meta($user->ID, '_api_tokens', array_values($tokens));

        return rest_ensure_response([
            'token_type' => 'Bearer',
            'token' => $token,
            'user_id' => $user->ID,
            'user_login' => $user->user_login,
            'user_email' => $user->user_email,
            'issued_at' => gmdate('c'),
        ]);
    }


}
