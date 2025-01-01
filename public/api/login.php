<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/UserHandler.php';
require_once __DIR__ . '/../../vendor/autoload.php';

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

// İstek verilerini al
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['email']) || empty($data['password'])) {
    jsonResponse(false, 'Email and password are required.');
}

try {
    // Veritabanı bağlantısı
    $dbHandler = new DatabaseHandler();
    $dbConnection = $dbHandler->getConnection();

    // Kullanıcı doğrulama
    $userHandler = new UserHandler($dbConnection);
    $response = $userHandler->login($data);

    // Başarılı giriş durumunda yanıt döndür
    if ($response['success']) {
        jsonResponse(true, $response['message'], $response['data']);
    } else {
        jsonResponse(false, $response['message'], null, $response['errors']);
    }
} catch (Exception $e) {
    // Hata durumunda loglama ve genel mesaj
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred, please try again later.');
}
