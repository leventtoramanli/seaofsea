<?php
require_once 'init.php'; // Veritabanı bağlantısı ve gerekli ayarlar

class User {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create (Yeni Kullanıcı Ekleme)
    public function createUser($name, $email, $password) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $query = "INSERT INTO users (name, email, password) VALUES (:name, :email, :password)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $hashedPassword);
        return $stmt->execute();
    }

    // Read (Tekil Kullanıcı veya Tüm Kullanıcıları Listele)
    public function getUser($id = null) {
        if ($id) {
            $query = "SELECT id, name, email FROM users WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $query = "SELECT id, name, email FROM users";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Update (Kullanıcı Bilgilerini Güncelle)
    public function updateUser($id, $name, $email) {
        $query = "UPDATE users SET name = :name, email = :email WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        return $stmt->execute();
    }

    // Delete (Kullanıcıyı Sil)
    public function deleteUser($id) {
        $query = "DELETE FROM users WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
}

?>
