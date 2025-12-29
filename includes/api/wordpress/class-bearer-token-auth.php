<?php

class RESTBridge_Bearer_Token_Auth {
	public function __construct() {
		add_filter('determine_current_user', [$this, 'maybe_authenticate_user'], 20);
	}

	public function maybe_authenticate_user($user_id) {
		if ($user_id) {
			return $user_id;
		}

		$auth = $this->get_authorization_header();
		if (!$auth) {
			return $user_id;
		}

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

	private function get_authorization_header() {
		$header = null;

		if (function_exists('getallheaders')) {
			$headers = getallheaders();
			foreach ($headers as $key => $value) {
				if (strcasecmp($key, 'Authorization') === 0) {
					$header = $value;
					break;
				}
			}
		}

		if (!$header) {
			if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
				$header = $_SERVER['HTTP_AUTHORIZATION'];
			} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
				$header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			}
		}

		return $header ? trim($header) : null;
	}
}

new RESTBridge_Bearer_Token_Auth();



// Add this to functions.php - Pure PHP JWT (no external files)
class SimpleJWT {
    private $key;
    
    public function __construct($key = 'your-super-secret-key-change-this') {
        $this->key = $key;
    }
    
    public function encode($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->key, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    public function decode($token) {
        list($header, $payload, $signature) = explode('.', $token);
        $expected = hash_hmac('sha256', $header . "." . $payload, $this->key, true);
        $actual = base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', (4 - strlen($signature) % 4)));
        
        if (!hash_equals($expected, $actual)) return false;
        
        $payload = json_decode(base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', (4 - strlen($payload) % 4))), true);
        return $payload;
    }
}
