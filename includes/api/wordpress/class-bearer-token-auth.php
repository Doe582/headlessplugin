<?php

class RESTBridge_Bearer_Token_Auth {
	public function __construct() {
		add_filter('determine_current_user', [$this, 'maybe_authenticate_user'], 20);
	}

	public function maybe_authenticate_user($user_id) {
		if ($user_id) {
			return $user_id;
		}

		if (!function_exists('getallheaders')) {
			return $user_id;
		}

		$headers = getallheaders();
		if (empty($headers['Authorization'])) {
			return $user_id;
		}

		$auth = trim($headers['Authorization']);
		if (!preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
			return $user_id;
		}

		$token = $matches[1];
		$users = get_users([
			'meta_key'   => '_api_token',
			'meta_value' => $token,
			'number'     => 1,
			'count_total'=> false,
		]);

		if (empty($users)) {
			return $user_id;
		}

		$user = $users[0];
		wp_set_current_user($user->ID);

		return $user->ID;
	}
}

new RESTBridge_Bearer_Token_Auth();

