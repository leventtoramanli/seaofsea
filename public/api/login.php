<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Parse request body
$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

function jsonResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data ?? [],
    ]);
    exit;
}

if (empty($email) || empty($password)) {
    jsonResponse(false, 'Email and password are required.');
}

try {
    // DatabaseHandler ile bağlantı
    $dbHandler = new DatabaseHandler();
    $dbConnection = $dbHandler->getConnection();

    $crud = new CRUDHandler($dbConnection);
    $user = $crud->read('users', ['email' => $email]);

    if (!$user) {
        jsonResponse(false, 'Invalid email or password.');
    }

    if (!password_verify($password, $user['password'])) {
        jsonResponse(false, 'Invalid email or password.');
    }

    // JWT creation
    $secretKey = $_ENV['JWT_SECRET'];
    $issuer = 'https://seaofsea.com/public/api/login.php';
    $audience = 'https://seaofsea.com';
    $issuedAt = time();
    $expirationTime = $issuedAt + 3600;

    $isVerified = $user['is_verified'] == 1;
    $role = $user['role'] ?? 'Guest';

    $payload = [
        'iss' => $issuer,
        'aud' => $audience,
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'data' => [
            'id' => $user['id'],
            'email' => $email,
            'name' => $user['name'],
            'surname' => $user['surname'],
            'role' => $role,
            'is_verified' => $isVerified,
        ],
    ];

    $jwt = JWT::encode($payload, $secretKey, 'HS256');

    $message = $isVerified
        ? 'Login successful.'
        : 'You are logged in as an anonymous user. Please verify your email for full access.';

    jsonResponse(true, $message, [
        'token' => $jwt,
        'is_verified' => $isVerified,
        'role' => $role,
    ]);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred, please try again later.');
}
