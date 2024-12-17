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
}
