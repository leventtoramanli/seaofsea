<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/PasswordResetHandler.php';

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

// Yalnızca POST isteklerini kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method.');
}

// Gelen veriyi al
$data = json_decode(file_get_contents('php://input'), true);
$email = trim($data['email'] ?? '');
$newPassword = $data['new_password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

// Giriş verilerini doğrula
if (empty($email) || empty($newPassword) || empty($confirmPassword)) {
    jsonResponse(false, 'Email, new password, and confirm password are required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Invalid email format.');
}
if ($newPassword !== $confirmPassword) {
    jsonResponse(false, 'Passwords do not match.');
}
if (strlen($newPassword) < 6) {
    jsonResponse(false, 'Password must be at least 6 characters.');
}

try {
    // Veritabanı bağlantısını oluştur
    $dbConnection = DatabaseHandler::getInstance()->getConnection();
    $resetHandler = new PasswordResetHandler($dbConnection);

    // Şifre sıfırla
    if ($resetHandler->resetPassword($email, $newPassword)) {
        jsonResponse(true, 'Password reset successfully.');
    } else {
        jsonResponse(false, 'Failed to reset the password. Please try again later.');
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir mesaj döndür
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred during password reset.');
}
?>