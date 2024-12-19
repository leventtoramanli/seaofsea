<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Composer Autoloader
use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Oturum başlatma
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Gerekli çevre değişkenlerinin kontrolü
$requiredEnvVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'];
foreach ($requiredEnvVars as $var) {
    if (!getenv($var)) {
        die("Hata: $var çevre değişkeni tanımlı değil.");
    }
}

// Hata raporlama ayarları (geliştirme ve üretim ortamlarına göre düzenleyin)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Üretim ortamında 0, geliştirme ortamında 1 olabilir

// Diğer genel yapılandırmalar...
