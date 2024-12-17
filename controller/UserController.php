<?php
require_once 'config/database.php';
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;

class UserController {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->conn;
    }

    public function login($email, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $payload = [
                'iss' => "seaofsea_api",
                'sub' => $user['id'],
                'iat' => time(),
                'exp' => time() + 3600
            ];
            $token = JWT::encode($payload, getenv('JWT_SECRET'), 'HS256');

            echo json_encode(["token" => $token]);
        } else {
            http_response_code(401);
            echo json_encode(["message" => "Invalid credentials"]);
        }
    }
}
