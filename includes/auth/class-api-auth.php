<?php
defined('ABSPATH') || exit;

class RESTBridge_API_Auth {

    public static function check_permission($request = null) {

        if ($request instanceof WP_REST_Request) {
            $request->get_json_params();
        }

        $user_id = get_current_user_id();

        if (!$user_id && $request) {

            $auth_header = $request->get_header('authorization');
            $token = null;

            // Token via query/body
            if (!$auth_header) {
                $token = $request->get_param('token');
            }

            // Token via Bearer header
            if (!$token && $auth_header && preg_match('/Bearer\s+(\S+)/i', $auth_header, $m)) {
                $token = $m[1];
            }

            if ($token) {
                $users = get_users([
                    'meta_key'    => '_api_token',
                    'meta_value'  => $token,
                    'number'      => 1,
                    'count_total' => false,
                ]);

                if (!empty($users)) {
                    wp_set_current_user($users[0]->ID);
                    $user_id = $users[0]->ID;
                }
            }
        }

        return ($user_id && $user_id > 0);
    }
}
