<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use PDO;
use PDOException;

class DatabaseHandler {
    private static $instance = null; // Singleton için tekil örnek
    private $connection;

    // Yapıcı metod (Singleton ile dışarıdan çağrılamaz)
    private function __construct() {
        // .env dosyasını yükle
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
        $user = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASSWORD'];

        try {
            $this->connection = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Hata yönetimi için
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Varsayılan olarak associative array döndür
                PDO::ATTR_EMULATE_PREPARES => false // Gerçek prepare statement kullan
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed.");
        }
    }

    // Singleton getInstance metodu
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DatabaseHandler();
        }
        return self::$instance;
    }

    // PDO bağlantısını döndür
    public function getConnection() {
        return $this->connection;
    }

    // Bağlantıyı kapat
    public function closeConnection() {
        $this->connection = null;
    }
}
