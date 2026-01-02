<?php 

class SimpleJWT {

    private $key;

    public function __construct($key) {
        $this->key = $key;
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public function encode(array $payload, $expireSeconds = 86400) {

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];

        $payload['iat'] = time();
        $payload['exp'] = time() + $expireSeconds;

        $base64Header  = $this->base64UrlEncode(json_encode($header));
        $base64Payload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            $base64Header . '.' . $base64Payload,
            $this->key,
            true
        );

        $base64Signature = $this->base64UrlEncode($signature);

        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }

    public function decode($token) {

        if (count(explode('.', $token)) !== 3) {
            return false;
        }

        list($header, $payload, $signature) = explode('.', $token);

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, $this->key, true)
        );

        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($payload), true);

        if (!$payload || ($payload['exp'] ?? 0) < time()) {
            return false;
        }

        return $payload;
    }
}
