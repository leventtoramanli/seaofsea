<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php'; // CRUDHandler için gerekli dosya
require_once __DIR__ . '/../../vendor/autoload.php'; // JWT ve .env için gerekli

use Firebase\JWT\JWT;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Giriş bilgilerini al
$data = json_decode(file_get_contents('php://input'), true);

$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

try {
    // Veritabanı bağlantısını başlat
    $dbConnection = new mysqli(
        $_ENV['DB_HOST'],
        $_ENV['DB_USER'],
        $_ENV['DB_PASSWORD'],
        $_ENV['DB_NAME']
    );

    if ($dbConnection->connect_error) {
        throw new Exception('Database connection failed: ' . $dbConnection->connect_error);
    }

    $crud = new CRUDHandler($dbConnection);

    // Kullanıcıyı email ile bul
    $user = $crud->read('users', ['email' => $email]);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Şifreyi doğrula
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Email doğrulama kontrolü
    if ($user['is_verified'] == 0) {
        echo json_encode(['success' => true, 'message' => 'Please verify your email before logging in.']);
        exit;
    }

    // JWT oluştur
    $secretKey = $_ENV['JWT_SECRET'];
    $issuer = 'http://localhost'; // Test ortamında localhost
    $audience = 'http://localhost'; // Test ortamında localhost
    $issuedAt = time();
    $expirationTime = $issuedAt + 3600; // 1 saat geçerli

    $payload = [
        'iss' => $issuer,
        'aud' => $audience,
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'data' => [
            'id' => $user['id'],
            'email' => $email,
        ],
    ];

    $jwt = JWT::encode($payload, $secretKey, 'HS256');

    echo json_encode(['success' => true, 'token' => $jwt, 'message' => 'Login successful.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>
