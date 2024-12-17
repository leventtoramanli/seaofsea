<?php
namespace App\Controllers;

require_once 'config/database.php';
require_once 'vendor/autoload.php';
require_once 'utils/LoggerHelper.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LoggerHelper;


class UserController {
    /**
     * @OA\Post(
     *     path="/api/register",
     *     summary="Kullanıcı kaydı oluştur",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "surname", "email", "password"},
     *             @OA\Property(property="name", type="string", example="John"),
     *             @OA\Property(property="surname", type="string", example="Doe"),
     *             @OA\Property(property="email", type="string", format="email", example="john@example.com"),
     *             @OA\Property(property="password", type="string", example="password123")
     *         )
     *     ),
     *     @OA\Response(response=201, description="User registered successfully"),
     *     @OA\Response(response=400, description="Validation error")
     * )
     */
    private $db;

    public function __construct() {
        $database = new \Database();
        $this->db = $database->conn;
    }

    // Kullanıcı Kaydı
    public function register($name, $surname, $email, $password) {
        try {
            // E-posta kontrolü
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->bindParam(":email", $email);
            $stmt->execute();
    
            if ($stmt->rowCount() > 0) {
                throw new \Exception("Email already registered");
            }
    
            // Şifre uzunluğu kontrolü
            if (strlen($password) < 8) {
                throw new \Exception("Password must be at least 8 characters long");
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
    
            // Loglama
            LoggerHelper::getLogger()->info("New user registered", ["email" => $email]);
    
            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "User registered successfully"]);
        } catch (\Exception $e) {
            LoggerHelper::getLogger()->error($e->getMessage(), ["email" => $email]);
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    

    // Kullanıcı Girişi
    public function login($email, $password) {
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
            $token = JWT::encode($payload, getenv('JWT_SECRET'), 'HS256');

            echo json_encode(["token" => $token, "message" => "Login successful"]);
        } else {
            http_response_code(401);
            echo json_encode(["message" => "Invalid credentials"]);
        }
    }

    // Kullanıcı Listeleme (CRUD: Read)
    public function getUsers() {
        \AuthMiddleware::checkRole('admin'); // Sadece admin erişebilir
    
        $stmt = $this->db->query("SELECT id, name, surname, email FROM users");
        $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
        echo json_encode($users);
    }

    // Kullanıcı Güncelleme (CRUD: Update)
    public function updateUser($id, $name, $surname, $email) {
        $userData = \AuthMiddleware::validateToken();
    
        if ($userData['sub'] != $id) { // Kendi verilerini güncelleme kontrolü
            http_response_code(403);
            echo json_encode(["message" => "You can only update your own data"]);
            return;
        }
    
        $stmt = $this->db->prepare("UPDATE users SET name = :name, surname = :surname, email = :email WHERE id = :id");
        $stmt->execute([
            ":name" => $name,
            ":surname" => $surname,
            ":email" => $email,
            ":id" => $id
        ]);
    
        echo json_encode(["message" => "User updated successfully"]);
    }    

    // Kullanıcı Silme (CRUD: Delete)
    public function deleteUser($id) {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute([":id" => $id]);
        echo json_encode(["message" => "User deleted successfully"]);
    }

    public function resetPasswordRequest($email) {
        // Kullanıcıyı email ile bul
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    
        if (!$user) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Email not found"]);
            return;
        }
    
        // Reset token oluştur
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600); // Token 1 saat geçerli
    
        // Token'ı veritabanına kaydet
        $stmt = $this->db->prepare("UPDATE users SET reset_token = :token, reset_token_expiry = :expiry WHERE id = :id");
        $stmt->execute([
            ":token" => $token,
            ":expiry" => $expiry,
            ":id" => $user['id']
        ]);
    
        // Token'ı e-posta olarak kullanıcıya gönder (örnek)
        echo json_encode(["status" => "success", "message" => "Reset token sent", "token" => $token]);
    }
    
    
    public function resetPasswordConfirm($token, $newPassword) {
        // Token kontrolü
        $stmt = $this->db->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
    
        if ($stmt->rowCount() == 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
            return;
        }
    
        // Yeni şifreyi hash'le
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
    
        // Şifreyi güncelle ve token'ı temizle
        $stmt = $this->db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id");
        $stmt->execute([
            ":password" => $hashedPassword,
            ":id" => $user['id']
        ]);
    
        echo json_encode(["status" => "success", "message" => "Password reset successfully"]);
    }    
    
}
