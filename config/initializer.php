<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Composer Autoloader
use Dotenv\Dotenv;

try {
    if (!defined('DOTENV_LOADED')) {
        define('DOTENV_LOADED', true);
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    }
} catch (Exception $e) {
    die("Dotenv yükleme hatası: " . $e->getMessage());
}

// Gerekli çevre değişkenlerinin kontrolü
$requiredEnvVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'JWT_SECRET'];
foreach ($requiredEnvVars as $var) {
    if (!isset($_ENV[$var]) || ($_ENV[$var] === '' && $var !== 'DB_PASS')) {
        die("Hata: $var çevre değişkeni tanımlı değil veya boş.");
    }
}

// Hata raporlama ayarları
error_reporting(E_ALL);
ini_set('display_errors', 1);
