<?php /*
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

// Giriş için gerekli verilerin doğruluğunu kontrol et
if (!$data || empty($data['email']) || empty($data['password'])) {
    jsonResponse(false, 'Email and password are required.');
}

try {
    // Veritabanı bağlantısını oluştur
    $dbConnection = DatabaseHandler::getInstance()->getConnection();

    // UserHandler sınıfını başlat
    $userHandler = new UserHandler($dbConnection);

    // Kullanıcı girişini işle
    $response = $userHandler->login($data);

    // Yanıtı döndür
    if ($response['success']) {
        jsonResponse(true, $response['message'], $response['data']);
    } else {
        jsonResponse(false, $response['message'], null, $response['errors']);
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir mesaj döndür
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred, please try again later.');
}
