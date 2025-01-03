<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../lib/handlers/UserHandler.php';
require_once __DIR__ . '/../lib/handlers/PasswordResetHandler.php';
require_once __DIR__ . '/../lib/handlers/LoggerHelper.php';

use Dotenv\Dotenv;
use Monolog\Logger;
//use Monolog\Handler\StreamHandler;

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

$logger = new LoggerHandler();

try {
    $logger->log(Logger::INFO, "API Request started");
} catch (Exception $e) {
    error_log('API request log failed: ' . $e->getMessage());
}

try {
    // İstek verilerini al
    $data = json_decode(file_get_contents('php://input'), true);
    $endpoint = $_GET['endpoint'] ?? null;

    if (!$endpoint) {
        jsonResponse(false, 'Endpoint is required.');
    }

    $logger->log(Logger::INFO, "API Request received", ['endpoint' => $endpoint]);

    switch ($endpoint) {
        case 'login':
            $userHandler = new UserHandler();  
            $response = $userHandler->login($data);
            jsonResponse($response['success'], $response['message'], $response['data'], $response['errors']);
            break;

        case 'register':
            $userHandler = new UserHandler();  
            $response = $userHandler->validateAndRegisterUser($data);
            jsonResponse($response['success'], $response['message'], $response['data'], $response['errors']);
            break;

        case 'reset_password_request':
            $resetHandler = new PasswordResetHandler();
            $email = $data['email'] ?? null;
            if (!$email) {
                jsonResponse(false, 'Email is required.');
            }
            $token = $resetHandler->createResetRequest($email);
            if ($token) {
                jsonResponse(true, 'Password reset request created.', ['token' => $token]);
            } else {
                jsonResponse(false, 'Failed to create password reset request.');
            }
            break;

        case 'reset_password':
            $resetHandler = new PasswordResetHandler();
            $email = $data['email'] ?? null;
            $newPassword = $data['new_password'] ?? null;
            if (!$email || !$newPassword) {
                jsonResponse(false, 'Email and new password are required.');
            }
            if ($resetHandler->resetPassword($email, $newPassword)) {
                jsonResponse(true, 'Password reset successfully.');
            } else {
                jsonResponse(false, 'Failed to reset password.');
            }
            break;

        default:
            jsonResponse(false, 'Invalid endpoint.');
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir hata yanıtı gönder
    $logger->log(Logger::ERROR, 'API Error: ' . $e->getMessage(), ['exception' => $e]);
    jsonResponse(false, 'An unexpected error occurred.');
}

