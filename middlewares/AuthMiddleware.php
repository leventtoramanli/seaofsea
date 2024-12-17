<?php
namespace App\Middlewares;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {
    public static function validateToken() {
        $headers = apache_request_headers();

        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "Unauthorized: No token provided"]);
            exit;
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $secretKey = getenv('JWT_SECRET') ?: 'default_secret';

        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
            exit;
        }
    }

    public static function checkRole($requiredRole, $userRole) {
        if ($requiredRole !== $userRole) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Forbidden: You do not have permission"]);
            exit;
        }
    }
}
