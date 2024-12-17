<?php
require_once 'vendor/autoload.php'; // Composer autoload

use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Örnek kullanım: Ortam değişkenlerini kullanma
$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

// Veritabanı bağlantısı
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected successfully.";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
