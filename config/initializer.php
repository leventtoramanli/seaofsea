<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer Autoloader
use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__ . '/');
$dotenv->load();

// Oturum başlatma işlemi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Gerekli .env kontrolleri
if (!getenv('JWT_SECRET')) {
    die('Hata: .env dosyası yüklenemedi veya JWT_SECRET tanımlı değil.');
}

// Global loglama veya hata yakalama ayarları eklenebilir
