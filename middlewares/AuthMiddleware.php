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
            return (array) $decoded; // Token'ı diziye çeviriyoruz
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(["message" => "Invalid Token", "error" => $e->getMessage()]);
            exit;
        }
    }

    public static function checkRole($requiredRole) {
        $userData = self::validateToken();

        // Kullanıcı rolünü al
        $db = new Database();
        $stmt = $db->conn->prepare("SELECT r.name FROM Roles r
                                    JOIN Users u ON u.role_id = r.id
                                    WHERE u.id = :id");
        $stmt->bindParam(":id", $userData['sub']);
        $stmt->execute();
        $role = $stmt->fetchColumn();

        if ($role !== $requiredRole) {
            http_response_code(403);
            echo json_encode(["message" => "Forbidden: Insufficient permissions"]);
            exit;
        }
    }
}

