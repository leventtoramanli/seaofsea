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

    public function register($user_data) {
        $name = $user_data['name'];
        $surname = $user_data['surname'];
        $email = $user_data['email'];
        $password = $user_data['password'];
        $role_id = $user_data['role_id'] ?? 2; // Default role is user
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
            $stmt->bindParam(":email", $email, \PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    
            if (!$user || !password_verify($password, $user['password'])) {
                throw new \Exception("Invalid credentials");
            }
    
            $payload = [
                "iss" => "seaofsea_api",
                "sub" => $user['id'],
                "iat" => time(),
                "exp" => time() + 3600
            ];
            $secretKey = $_ENV['JWT_SECRET'];
            $token = JWT::encode($payload, $secretKey, 'HS256');
    
            echo json_encode(["status" => "success", "token" => $token, "message" => "Login successful"]);
        } catch (\Exception $e) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    

    public function getUsers($page = 1, $limit = 10) {
        try {
            $userData = \App\Middlewares\AuthMiddleware::validateToken();
            $userRole = $this->getUserRole($userData['sub']);
    
            if ($userRole !== 'admin') {
                throw new \Exception("Forbidden: You do not have permission");
            }
    
            // Sayfalama hesaplaması
            $offset = ($page - 1) * $limit;
    
            // Kullanıcıları getirme
            $stmt = $this->db->prepare("SELECT id, name, surname, email, created_at 
                                        FROM users 
                                        LIMIT :limit OFFSET :offset");
            $stmt->bindParam(":limit", $limit, \PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, \PDO::PARAM_INT);
            $stmt->execute();
    
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
            echo json_encode(["status" => "success", "data" => $users]);
        } catch (\Exception $e) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    public function updateUser($id, $name, $surname, $email, $role_id) {
        try {
            $userData = \App\Middlewares\AuthMiddleware::validateToken();
            $userRole = $this->getUserRole($userData['sub']);
    
            if ($userRole !== 'admin' && $userData['sub'] != $id) {
                throw new \Exception("You can only update your own data");
            }
    
            // Boş değer kontrolleri
            if (empty($name) || empty($surname) || empty($email)) {
                throw new \Exception("Name, surname, and email cannot be empty");
            }
    
            // Şifre doğrulama
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid email format");
            }
    
            // Kullanıcı güncelleme
            $stmt = $this->db->prepare("UPDATE users 
                                        SET name = :name, surname = :surname, email = :email, role_id = :role_id 
                                        WHERE id = :id");
            $stmt->execute([
                ":name" => $name,
                ":surname" => $surname,
                ":email" => $email,
                ":role_id" => $role_id,
                ":id" => $id
            ]);
    
            echo json_encode(["status" => "success", "message" => "User updated successfully"]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    

    public function deleteUser($id) {
        try {
            $userData = \App\Middlewares\AuthMiddleware::validateToken();
            $userRole = $this->getUserRole($userData['sub']);
    
            if ($userRole !== 'admin') {
                throw new \Exception("You do not have permission to delete users");
            }
    
            // Soft delete işlemi
            $stmt = $this->db->prepare("UPDATE users SET deleted_at = NOW() WHERE id = :id");
            $stmt->execute([":id" => $id]);
    
            echo json_encode(["status" => "success", "message" => "User deleted successfully"]);
        } catch (\Exception $e) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    
    public function resetPasswordRequest($email) {
        try {
            // Kullanıcıyı email ile bul
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    
            if (!$user) {
                throw new \Exception("Email address not found");
            }
    
            // Reset token oluştur
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 saat geçerli
    
            // Token'ı veritabanına kaydet
            $stmt = $this->db->prepare("UPDATE users SET reset_token = :token, reset_token_expiry = :expiry WHERE id = :id");
            $stmt->execute([
                ":token" => $token,
                ":expiry" => $expiry,
                ":id" => $user['id']
            ]);
    
            // Token'ı geri dön (gerçek projede bu email ile gönderilir)
            echo json_encode(["status" => "success", "message" => "Reset token generated", "token" => $token]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    public function resetPasswordConfirm($token, $newPassword) {
        try {
            // Token kontrolü
            $stmt = $this->db->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()");
            $stmt->bindParam(":token", $token);
            $stmt->execute();
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    
            if (!$user) {
                throw new \Exception("Invalid or expired token");
            }
    
            // Şifre doğrulama
            if (strlen($newPassword) < 8) {
                throw new \Exception("Password must be at least 8 characters long");
            }
    
            // Şifreyi hash'le
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    
            // Şifreyi güncelle ve token'ı sil
            $stmt = $this->db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id");
            $stmt->execute([
                ":password" => $hashedPassword,
                ":id" => $user['id']
            ]);
    
            echo json_encode(["status" => "success", "message" => "Password reset successfully"]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }    
}
