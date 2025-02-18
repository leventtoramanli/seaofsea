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
require_once __DIR__ . '/../lib/handlers/ImageUploadHandler.php';

// JSON yanıt fonksiyonu
function jsonResponse($success, $message, $data = null, $errors = null) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data ?? [],
        'errors' => $errors ?? []
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
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
        case 'get_user_info':
            try {
                $userId = getUserIdFromToken();
                if (!$userId) {
                    jsonResponse(false, 'User ID is required for info.');
                }
                $crudHandler = new CRUDHandler();
                $user = $crudHandler->read('users', ['id' => $userId], ['*'], false);
        
                if ($user) {
                    $user = (array) $user;
                    unset($user['password'], $user['reset_token'], $user['reset_token_expiry']);
        
                    jsonResponse(true, 'User info retrieved successfully.', $user);
                } else {
                    jsonResponse(false, 'User not found.');
                }
            } catch (Exception $e) {
                jsonResponse(false, 'Error retrieving user info.', null, ['error' => $e->getMessage()]);
            }
            break;        
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
        
        case 'roles':
            $userHandler = new UserHandler();
            $roles = $userHandler->getAllRoles();
            if ($roles) {
                jsonResponse(true, 'Roles retrieved successfully.', $roles);
            } else {
                jsonResponse(false, 'No roles found.');
            }
            break;
        case 'upload_cover_image':
            try {
                $file = $_FILES['file'] ?? null;
                $imageBase64 = $data['image_base64'] ?? null;
                $userId = $data['user_id'] ?? $_POST['user_id'] ?? getUserIdFromToken();
                $meta = $data['meta'] ?? [];
                $maxSize = 1920;
        
                if (!$userId) {
                    jsonResponse(false, 'User ID is required.');
                }
        
                $uploadHandler = new App\Handlers\ImageUploadHandler('images/user/covers');
                $fileName = $uploadHandler->handleUpload($file, $imageBase64, $userId, $meta, $maxSize);
        
                $crudHandler = new CRUDHandler();
                $updateResult = $crudHandler->update('users', ['cover_image' => $fileName], ['id' => $userId]);
        
                if ($updateResult) {
                    jsonResponse(true, 'User image updated successfully.', ['file_name' => $fileName]);
                } else {
                    jsonResponse(false, 'Failed to update user image in database.');
                }
            } catch (Exception $e) {
                jsonResponse(false, 'Error occurred: ' . $e->getMessage());
            }
            break;            

        case 'update_user':
            try {
                $userId = $data['user_id'] ?? null;
                if (!$userId) {
                    jsonResponse(false, 'User ID is required.');
                    break;
                }
        
                unset($data['user_id']); // Güncellenecek veri kümesinden user_id'yi çıkar
        
                $userHandler = new UserHandler();
                $response = $userHandler->updateUser($userId, $data);
                jsonResponse($response['success'], $response['message']);
            } catch (Exception $e) {
                jsonResponse(false, 'Error updating user.', null, ['error' => $e->getMessage()]);
            }
            break;            
        case 'check_user_data':
            $userId = $data['user_id'] ?? null;
            if (!$userId) {
                jsonResponse(false, 'User ID is required.');
                return;
            }
            $userHandler = new UserHandler();
            $user = $userHandler->getUserById($userId);
            if ($user) {
                if (is_array($user) && isset($user[0])) {
                    $user = $user[0]; 
                }
                jsonResponse(true, 'User data retrieved successfully.', ['items' => (array) $user->items]);
            } else {
                jsonResponse(false, 'User not found.');
            }
            break;
        case 'check_cover_images':
            try {
                $userId = getUserIdFromToken();
                if (!$userId) {
                    jsonResponse(false, 'User ID is required.');
                }
                $crudHandler = new CRUDHandler();
                $user = $crudHandler->read('users', ['id' => $userId], ['cover_image'], false);
                $user = (array) $user;
                if ($user && isset($user['cover_image'])) {
                    jsonResponse(true, 'User cover image retrieved.', ['cover_image' => $user['cover_image']]);
                } else {
                    jsonResponse(false, 'No cover image found.');
                }
            } catch (Exception $e) {
                jsonResponse(false, 'Error fetching cover image: ' . $e->getMessage());
            }
            break;                       
        default:
            jsonResponse(false, 'Invalid endpoint.');
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir hata yanıtı gönder
    $logger->error('API Error', ['exception' => $e]);
    jsonResponse(false, 'An unexpected error occurred.', null, ['error' => $e->getMessage()]);
}
