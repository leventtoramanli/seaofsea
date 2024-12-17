<?php
require_once 'config/database.php';
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class UserController {
    private $db;

    public function __construct() {
        $database = new Database();
        $this->db = $database->conn;
    }

    // Kullanıcı Kaydı
    public function register($name, $surname, $email, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["message" => "User already exists"]);
            return;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(["message" => "Password must be at least 8 characters long"]);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid email format"]);
            return;
        }        

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (name, surname, email, password) VALUES (:name, :email, :password)");
        $stmt->execute([
            ":name" => $name,
            ":surname" => $surname,
            ":email" => $email,
            ":password" => $hashedPassword
        ]);

        http_response_code(201);
        echo json_encode(["message" => "User registered successfully"]);
    }

    // Kullanıcı Girişi
    public function login($email, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

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
        $stmt = $this->db->query("SELECT id, name, email FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
    }

    // Kullanıcı Güncelleme (CRUD: Update)
    public function updateUser($id, $name, $email) {
        $stmt = $this->db->prepare("UPDATE users SET name = :name, email = :email WHERE id = :id");
        $stmt->execute([
            ":name" => $name,
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
        // E-posta kontrolü
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            http_response_code(404);
            echo json_encode(["message" => "Email not found"]);
            return;
        }
    
        // Şifre sıfırlama token oluştur
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600); // Token 1 saat geçerli
    
        // Token'ı kaydet
        $stmt = $this->db->prepare("UPDATE users SET reset_token = :token, reset_token_expiry = :expiry WHERE id = :id");
        $stmt->execute([
            ":token" => $token,
            ":expiry" => $expiry,
            ":id" => $user['id']
        ]);
    
        // Token'ı e-posta ile gönder (örnek)
        echo json_encode(["message" => "Reset token sent", "token" => $token]);
    }
    
    public function resetPasswordConfirm($token, $newPassword) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
    
        if ($stmt->rowCount() == 0) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or expired token"]);
            return;
        }
    
        // Yeni şifreyi hash'le
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
        // Şifreyi güncelle
        $stmt = $this->db->prepare("UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id");
        $stmt->execute([
            ":password" => $hashedPassword,
            ":id" => $user['id']
        ]);
    
        echo json_encode(["message" => "Password reset successfully"]);
    }
    
}
