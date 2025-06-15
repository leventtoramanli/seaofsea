<?php
/*
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/UserHandler.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

// JSON yanıt fonksiyonu
function jsonResponse($success, $message, $data = null, $errors = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data ?? [],
        'errors' => $errors ?? []
    ]);
    exit;
}

// Çevresel değişkenleri yükle
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

// Oturum başlatma ve kayıt denemesi sınırı
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['register_attempts'])) {
    $_SESSION['register_attempts'] = 0;
    $_SESSION['last_register_time'] = time();
}

if ($_SESSION['register_attempts'] >= 5 && (time() - $_SESSION['last_register_time']) < 60) {
    jsonResponse(false, 'Too many registration attempts. Please try again later.');
}

$_SESSION['register_attempts']++;
$_SESSION['last_register_time'] = time();

try {
    // Veritabanı bağlantısını oluştur
    $dbConnection = DatabaseHandler::getInstance()->getConnection();

    // UserHandler sınıfını başlat
    $userHandler = new UserHandler($dbConnection);

    // Gelen veriyi al
    $inputData = json_decode(file_get_contents('php://input'), true);
    if (!$inputData) {
        jsonResponse(false, 'Invalid input data.');
    }

    // Kullanıcı kaydını işle
    $result = $userHandler->validateAndRegisterUser($inputData);

    // Sonucu döndür
    if ($result['success']) {
        jsonResponse(true, $result['message']);
    } else {
        jsonResponse(false, $result['message'], null, $result['errors']);
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir mesaj döndür
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred during registration. Please try again later.');
}
