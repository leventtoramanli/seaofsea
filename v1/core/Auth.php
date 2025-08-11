<?php

class Auth
{
    public static function getTokenFromHeader(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        Logger::info("Auth header: " . json_encode($authHeader));

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function check(): ?array
    {
        $config = require __DIR__ . '/../config/config.php';
        $secret = $config['jwt']['secret'] ?? '';

        $token = self::getTokenFromHeader();
        if (!$token || !$secret) {
            return null;
        }

        $payload = JWT::decode($token, $secret);
        if (!$payload || !isset($payload['user_id'])) {
            return null;
        }

        return $payload; // örn: ['user_id' => 5, 'exp' => 1234567890]
    }

    public static function requireAuth(): array
    {
        $auth = self::check();
        if (!$auth) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized request.'
            ]);
            exit;
        }
        return $auth;
    }
}
