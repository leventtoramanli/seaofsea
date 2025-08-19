<?php

class Auth
{
    public static function getTokenFromHeader(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
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

        try {
            $payload = JWT::decode($token, $secret);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$payload || !isset($payload['user_id'])) return null;
        if (isset($payload['exp']) && (int)$payload['exp'] < time()) return null;

        return $payload;
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
