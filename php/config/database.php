<?php
class Database {
    private $host = "localhost";       // Veritabanı sunucusu
    private $db_name = "your_db_name"; // Veritabanı ismi
    private $username = "root";        // Kullanıcı adı
    private $password = "";            // Şifre
    private $conn;

    // Veritabanı bağlantısını başlatır
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            echo "Database connection error: " . $exception->getMessage();
        }

        return $this->conn;
    }
}
?>
