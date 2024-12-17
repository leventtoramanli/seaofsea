<?php
namespace App\Controllers;

require_once 'config/database.php';
require_once 'vendor/autoload.php';
require_once 'utils/LoggerHelper.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Utils\LoggerHelper;
use App\Config\Database;

class UserController {
    private $db;

    private function getUserRole($userId) {
        $stmt = $this->db->prepare("SELECT r.name AS role_name 
                                    FROM users u
                                    JOIN roles r ON u.role_id = r.id
                                    WHERE u.id = :user_id");
        $stmt->bindParam(":user_id", $userId);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    
        return $result ? $result['role_name'] : null;
    }
    

    public function __construct() {
        $database = new \Database();
        $this->db = $database->conn;
    }

    public function register($name, $surname, $email, $password) {
        try {
            // Boş değer kontrolü
            if (empty($name) || empty($surname) || empty($email) || empty($password)) {
                throw new \Exception("All fields (name, surname, email, password) are required");
            }
    
            // E-posta doğrulaması
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid email format");
            }
    
            // Şifre uzunluğu kontrolü
            if (strlen($password) < 8) {
                throw new \Exception("Password must be at least 8 characters long");
            }
    
            // E-posta kontrolü (kullanıcı zaten kayıtlı mı?)
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->bindParam(":email", $email);
            $stmt->execute();
    
            if ($stmt->rowCount() > 0) {
                throw new \Exception("Email already registered");
            }
    
            // Şifre hash'leme
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    
            // Kullanıcıyı ekle
            $stmt = $this->db->prepare("INSERT INTO users (name, surname, email, password) VALUES (:name, :surname, :email, :password)");
            $stmt->execute([
                ":name" => $name,
                ":surname" => $surname,
                ":email" => $email,
                ":password" => $hashedPassword
            ]);
    
            LoggerHelper::getLogger()->info("New user registered", ["email" => $email]);
            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "User registered successfully"]);
        } catch (\Exception $e) {
            LoggerHelper::getLogger()->error($e->getMessage(), ["email" => $email]);
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    

    public function login($email, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $payload = [
                    "iss" => "seaofsea_api",
                    "sub" => $user['id'],
                    "iat" => time(),
                    "exp" => time() + 3600
                ];

                $secretKey = getenv('JWT_SECRET') ?: 'default_secret';
                $token = JWT::encode($payload, $secretKey, 'HS256');

                echo json_encode(["status" => "success", "token" => $token, "message" => "Login successful"]);
            } else {
                throw new \Exception("Invalid credentials");
            }
        } catch (\Exception $e) {
            http_response_code(401);
            LoggerHelper::getLogger()->error($e->getMessage());
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    public function getUsers() {
        try {
            $userData = \App\Middlewares\AuthMiddleware::validateToken();
    
            // Kullanıcının rolünü al
            $userRole = $this->getUserRole($userData['sub']);
    
            // Rol kontrolü: sadece admin erişebilir
            \App\Middlewares\AuthMiddleware::checkRole('admin', $userRole);
    
            $stmt = $this->db->query("SELECT id, name, surname, email FROM users");
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
            echo json_encode(["status" => "success", "data" => $users]);
        } catch (\Exception $e) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }    
    

    public function updateUser($id, $name, $surname, $email) {
        try {
            $userData = \AuthMiddleware::validateToken();
            if ($userData['sub'] != $id) {
                throw new \Exception("You can only update your own data");
            }

            $stmt = $this->db->prepare("UPDATE users SET name = :name, surname = :surname, email = :email WHERE id = :id");
            $stmt->execute([
                ":name" => $name,
                ":surname" => $surname,
                ":email" => $email,
                ":id" => $id
            ]);

            echo json_encode(["status" => "success", "message" => "User updated successfully"]);
        } catch (\Exception $e) {
            http_response_code(403);
            LoggerHelper::getLogger()->error($e->getMessage());
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    public function deleteUser($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([":id" => $id]);
            echo json_encode(["status" => "success", "message" => "User deleted successfully"]);
        } catch (\Exception $e) {
            http_response_code(500);
            LoggerHelper::getLogger()->error($e->getMessage());
            echo json_encode(["status" => "error", "message" => "An error occurred while deleting user"]);
        }
    }
}
