<?php
defined('ABSPATH') || exit;

class RESTBridge_Shipping_Address_API {

    const META_KEY = '_styluza_shipping_addresses';

    public function register_routes() {

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/addresses', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_addresses'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/addresses/add', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_address'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/addresses/update', [
            'methods'  => 'POST',
            'callback' => [$this, 'update_address'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/addresses/delete', [
            'methods'  => 'POST',
            'callback' => [$this, 'delete_address'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(RESTBRIDGE_API_NAMESPACE, '/addresses/set-default', [
            'methods'  => 'POST',
            'callback' => [$this, 'set_default_address'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /* -------------------------
     * Permission (JWT based)
     * ------------------------- */
    public function check_permission($request) {
        $auth = $request->get_header('authorization');

        if (!$auth || stripos($auth, 'Bearer ') !== 0) {
            return false;
        }

        try {
            $token = trim(str_ireplace('Bearer', '', $auth));
            $jwt   = new SimpleJWT(MY_JWT_SECRET);
            $data  = $jwt->decode($token);

            $user_id = (int) ($data['user_id'] ?? 0);
            if (!$user_id) return false;

            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, false, true);

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    /* -------------------------
     * Get addresses
     * ------------------------- */
    public function get_addresses() {
        return get_user_meta(
            get_current_user_id(),
            self::META_KEY,
            true
        ) ?: [];
    }

    /* -------------------------
     * Add address
     * ------------------------- */
    public function add_address(WP_REST_Request $request) {

        $user_id   = get_current_user_id();
        $addresses = get_user_meta($user_id, self::META_KEY, true) ?: [];

        $new = $this->sanitize_address($request);
        $new['id'] = uniqid('addr_');

        if ($new['is_default']) {
            foreach ($addresses as &$addr) {
                $addr['is_default'] = false;
            }
        }

        $addresses[] = $new;
        update_user_meta($user_id, self::META_KEY, $addresses);

        return $new;
    }

    /* -------------------------
     * Update address
     * ------------------------- */
    public function update_address(WP_REST_Request $request) {

        $user_id   = get_current_user_id();
        $addresses = get_user_meta($user_id, self::META_KEY, true) ?: [];
        $id        = sanitize_text_field($request['id']);

        foreach ($addresses as &$addr) {
            if ($addr['id'] === $id) {

                $updated = $this->sanitize_address($request);
                $updated['id'] = $id;

                if ($updated['is_default']) {
                    foreach ($addresses as &$a) {
                        $a['is_default'] = false;
                    }
                }

                $addr = array_merge($addr, $updated);
                update_user_meta($user_id, self::META_KEY, $addresses);

                return $addr;
            }
        }

        return new WP_Error('not_found', 'Address not found', ['status' => 404]);
    }

    /* -------------------------
     * Delete address
     * ------------------------- */
    public function delete_address(WP_REST_Request $request) {

        $user_id   = get_current_user_id();
        $addresses = get_user_meta($user_id, self::META_KEY, true) ?: [];
        $id        = sanitize_text_field($request['id']);

        $addresses = array_values(array_filter($addresses, fn($a) => $a['id'] !== $id));
        update_user_meta($user_id, self::META_KEY, $addresses);

        return ['success' => true];
    }

    /* -------------------------
     * Set default
     * ------------------------- */
    public function set_default_address(WP_REST_Request $request) {

        $user_id   = get_current_user_id();
        $addresses = get_user_meta($user_id, self::META_KEY, true) ?: [];
        $id        = sanitize_text_field($request['id']);

        foreach ($addresses as &$addr) {
            $addr['is_default'] = ($addr['id'] === $id);
        }

        update_user_meta($user_id, self::META_KEY, $addresses);
        return ['success' => true];
    }

    /* -------------------------
     * Sanitize
     * ------------------------- */
    private function sanitize_address(WP_REST_Request $r) {

        return [
            'first_name'   => sanitize_text_field($r['first_name']),
            'last_name'    => sanitize_text_field($r['last_name']),
            'phone'        => sanitize_text_field($r['phone']),
            'email'        => sanitize_email($r['email']),
            'address_1'    => sanitize_text_field($r['address_1']),
            'address_2'    => sanitize_text_field($r['address_2'] ?? ''),
            'city'         => sanitize_text_field($r['city']),
            'state'        => sanitize_text_field($r['state']),
            'postcode'     => sanitize_text_field($r['postcode']),
            'country'      => sanitize_text_field($r['country']),
            'address_type' => sanitize_text_field($r['address_type']),
            'is_default'   => (bool) $r['is_default'],
        ];
    }
}
