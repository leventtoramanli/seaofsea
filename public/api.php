<?php

use Carbon\Traits\ToStringFormat;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/handlers/UserHandler.php';
require_once __DIR__ . '/../lib/handlers/PasswordResetHandler.php';
require_once __DIR__ . '/../lib/handlers/CRUDHandlers.php';

// JSON yanıt fonksiyonu
function jsonResponse($success, $message, $data = null, $errors = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data ?? [],
        'errors' => $errors ?? []
    ]);
}

try {
    $loggerInfo = getLoggerInfo(); // Merkezi logger

    // Gelen isteği ve endpoint'i al
    $data = json_decode(file_get_contents('php://input'), true);
    $endpoint = $_GET['endpoint'] ?? null;


    if (!$endpoint) {
        jsonResponse(false, 'Endpoint is required.');
    }

    // Endpoint yönlendirmesi
    switch ($endpoint) {
        case 'login':
            $userHandler = new UserHandler();
            $response = $userHandler->login($data);
            $data = $response['data'] ?? [];
            $message = $response['message'] ?? null;
        
            jsonResponse($response['success'], $message, $data);
            break;

        case 'register':
            $userHandler = new UserHandler();
            $response = $userHandler->validateAndRegisterUser($data);
            $data = $response['data'] ?? [];
            $message = $response['message'] ?? null;
            jsonResponse($response['success'], $message, $data);
            break;

        case 'reset_password_request':
            $resetHandler = new PasswordResetHandler();
            $email = $data['email'] ?? null;
            if (!$email) {
                jsonResponse(false, 'Email is required.');
            }
            $token = $resetHandler->createResetRequest($email);
            if ($token) {
                jsonResponse(true, 'Password reset request created successfully.', ['token' => $token]);
            } else {
                jsonResponse(false, 'Failed to create password reset request.');
            }
            break;

        case 'reset_password':
            $resetHandler = new PasswordResetHandler();
            $email = $data['email'] ?? null;
            $newPassword = $data['new_password'] ?? null;
            $confirmPassword = $data['confirm_password'] ?? null;
            if (!$email || !$newPassword || !$confirmPassword) {
                jsonResponse(false, 'Email, new password, and confirm password are required.');
            }
            if ($newPassword !== $confirmPassword) {
                jsonResponse(false, 'Passwords do not match.');
            }
            if ($resetHandler->resetPassword($email, $newPassword)) {
                jsonResponse(true, 'Password reset successfully.');
            } else {
                jsonResponse(false, 'Failed to reset password.');
            }
            break;

        case 'get_users_with_roles':
            $userHandler = new UserHandler();
            $response = $userHandler->getUsersWithRoles();
            jsonResponse(true, 'Users retrieved successfully.', $response);
            break;

        case 'refresh_token':
            try {
                $data = json_decode(file_get_contents('php://input'), true);
                $refreshToken = $data['refresh_token'] ?? null;
        
                $logger->info('Refresh token request received.', ['refresh_token' => $refreshToken]);
        
                if (!$refreshToken) {
                    jsonResponse(false, 'Refresh token is required.');
                }
        
                $userHandler = new UserHandler();
                $response = $userHandler->refreshAccessToken($refreshToken);
                jsonResponse($response['success'], $response['message'], $response['data']);
            } catch (Exception $e) {
                $logger->error('Error during token refresh.', ['exception' => $e]);
                jsonResponse(false, 'An error occurred while refreshing token.', null, ['error' => $e->getMessage()]);
            }
            break;
        
        case 'logout':
            $data = json_decode(file_get_contents('php://input'), true);

            try {
                
                $refreshToken = $data['refresh_token'] ?? null;
                $deviceUUID = $data['device_uuid'] ?? null;
                $allDevices = $data['all_devices'] ?? false;
        
                $logger->info('Logout request received.', ['refresh_token' => $refreshToken, 'device_uuid' => $deviceUUID, 'all_devices' => $allDevices]);
                if (!$refreshToken) {
                    jsonResponse(false, 'Refresh token is required.');
                }
        
                $userHandler = new UserHandler();
                $response = $userHandler->logout($refreshToken, $deviceUUID, $allDevices);
                jsonResponse($response['success'], $response['message']);
            } catch (Exception $e) {
                $logger->error('Error during logout.', ['exception' => $e]);
                jsonResponse(false, 'An error occurred while logging out.');
            }
            break;
        case 'check_token':
            $userHandler = new UserHandler();
            $response = $userHandler->validateToken($data);
            $data = $response['data'] ?? [];
            $message = $response['message'] ?? null;
        
            jsonResponse($response['success'], $message, $data);
            break;

        default:
            jsonResponse(false, 'Invalid endpoint.');
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir hata yanıtı gönder
    $logger->error('API Error', ['exception' => $e]);
    jsonResponse(false, 'An unexpected error occurred.', null, ['error' => $e->getMessage()]);
}
