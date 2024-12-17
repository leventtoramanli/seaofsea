<?php
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {
    public static function validateToken() {
        $headers = apache_request_headers();

        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(["message" => "Unauthorized"]);
            exit;
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $secretKey = getenv('JWT_SECRET');

        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            return $decoded;
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(["message" => "Invalid Token", "error" => $e->getMessage()]);
            exit;
        }
    }
}
