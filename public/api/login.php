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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Çevresel değişkenleri yükle
if (!file_exists(__DIR__ . '/../../.env')) {
    die('.env file is missing in the specified directory.');
}
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// İstek verilerini al
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

// Giriş için gerekli bilgilerin kontrolü
if (empty($email) || empty($password)) {
    jsonResponse(false, 'Email and password are required.');
}

try {
    // Veritabanı bağlantısı
    $dbHandler = new DatabaseHandler();
    $dbConnection = $dbHandler->getConnection();

    // Kullanıcı doğrulama
    $userHandler = new UserHandler($dbConnection);
    $response = $userHandler->login($email, $password);

    // Giriş yanıtını döndür
    jsonResponse($response['success'], $response['message'], $response['data'] ?? null, $response['errors'] ?? null);
} catch (Exception $e) {
    // Hata durumunda loglama ve genel mesaj
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred, please try again later.');
}
?>