<?php

header('Content-Type: application/json; charset=UTF-8');

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
    $db = new DatabaseHandler();
    $userHandler = new UserHandler();
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
                    http_response_code(401);
                    jsonResponse(false, 'User ID is required for info.');
                    exit();
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
                http_response_code(401);
                $logger->error('Error retrieving user info.', ['exception' => $e]);
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
                $refreshToken = $data['refresh_token'] ?? null;
                $logger->error("🔄 Refresh Token API Çağrıldı. Gelen token: " . json_encode($refreshToken));
                if (!$refreshToken) {
                    throw new Exception('Refresh token is required.');
                }
                $response = $userHandler->refreshAccessToken($refreshToken);
                $logger->error("📢 Refresh Token Sonucu: " . json_encode($response));
                jsonResponse($response['success'], $response['message'], $response['data'] ?? null);
            } catch (Exception $e) {
                $logger->error("❌ Refresh Token Hatası: " . $e->getMessage());
                jsonResponse(false, 'Error refreshing token.', null, ['error' => $e->getMessage()]);
            }
            break;
        
        case 'logout':
            try {
                $refreshToken = $data['refresh_token'] ?? null;
                $deviceUUID = $data['device_uuid'] ?? null;
                $allDevices = $data['all_devices'] ?? false;

                if (!$refreshToken) {
                    throw new Exception('Refresh token is required.');
                }
                $response = $userHandler->logout($refreshToken, $deviceUUID, $allDevices);
                jsonResponse($response['success'], $response['message']);
            } catch (Exception $e) {
                jsonResponse(false, 'Error logging out.', null, ['error' => $e->getMessage()]);
            }
            break;
        case 'change_password':
            $result = $userHandler->changePassword($data);
            if (isset($result['success']) && $result['success']) {
                jsonResponse(true, $result['message']);
            } else {
                jsonResponse(false, $result['message']);
            }
            break;            
        case 'check_token':
            $authHeader = getAuthorizationHeader();
            if (!$authHeader) {
                jsonResponse(false, "Authorization header missing.", null);
                exit;
            }
        
            $userHandler = new UserHandler();
            $userData = $userHandler->validateToken($authHeader); // Kullanıcıyı doğrula
        
            if (!$userData) {
                jsonResponse(false, "Invalid token.", null);
                exit;
            }
        
            // 🛠 **TOKEN SÜRESİNİ UZAT!**
            $newExpiryTime = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $query = "UPDATE users SET token_expiry = :expiry WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->execute(['expiry' => $newExpiryTime, 'user_id' => $userData['id']]);
        
            jsonResponse(true, "Token is valid, expiry extended.", ["expiry" => $newExpiryTime]);
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
        case 'upload_image':
            try {
                $file = $_FILES['file'] ?? null;
                $imageBase64 = $data['image_base64'] ?? null;
                $userId = $data['user_id'] ?? $_POST['user_id'] ?? getUserIdFromToken();
                $type = $data['type'] ?? $_POST['type'] ?? null;
                $maxSize = 1920;
        
                if (!$userId || !$type) {
                    jsonResponse(false, 'User ID and type are required.');
                }
        
                // 📌 Dinamik olarak klasör belirleme
                $validTypes = [
                    'cover' => 'images/user/covers',
                    'user' => 'images/user',
                    'blog' => 'images/blog',
                    'product' => 'images/products',
                ];
        
                if (!array_key_exists($type, $validTypes)) {
                    jsonResponse(false, 'Invalid image type.');
                }
        
                $uploadPath = $validTypes[$type];
                $uploadHandler = new App\Handlers\ImageUploadHandler($uploadPath);
                $fileName = $uploadHandler->handleUpload($file, $imageBase64, $userId, $maxSize);
        
                // 📌 Hangi kolonu güncelleyeceğimizi belirleme
                $columnMappings = [
                    'cover' => 'cover_image',
                    'user' => 'user_image',
                    'blog' => 'blog_image',
                    'product' => 'product_image',
                ];
                $column = $columnMappings[$type];
        
                // 📌 Veritabanında ilgili alanı güncelle
                $crudHandler = new CRUDHandler();
                $updateResult = $crudHandler->update('users', [$column => $fileName], ['id' => $userId]);
        
                if ($updateResult) {
                    jsonResponse(true, ucfirst($column) . ' updated successfully.', ['file_name' => $fileName]);
                } else {
                    jsonResponse(false, 'Failed to update image in database.');
                }
            } catch (Exception $e) {
                jsonResponse(false, 'Error occurred: ' . $e->getMessage());
            }
            break;            

        case 'update_user':
            try {
                $userId = $data['user_id'] ?? null;
                $newEmail = $data['email'] ?? null;
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
        case 'get_notification_settings':
            $result = $userHandler->getNotificationSettings($data);
            if (isset($result['success']) && $result['success']) {
                jsonResponse(true, $result['message'] ?? 'Success', $result['data'] ?? []);
            } else {
                jsonResponse(false, $result['message'] ?? 'An error occurred');
            }
            break;            
            
        case 'save_notification_settings':
            $result = $userHandler->saveNotificationSettings($data);
            jsonResponse($result['success'], $result['message']);
            break;
        case 'create_company':
            $crudHandler = new CRUDHandler();
            $userId = getUserIdFromToken();
        
            if (!$userId || empty($data['name'])) {
                jsonResponse(false, 'Company name is required.');
            }
        
            $companyId = $crudHandler->create('companies', [
                'name' => $data['name'],
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
            if ($companyId) {
                $crudHandler->create('user_company', [
                    'user_id' => $userId,
                    'company_id' => $companyId,
                    'role' => 'admin',
                    'is_active' => true,
                ]);
        
                jsonResponse(true, 'Company created successfully.', ['company_id' => $companyId]);
            } else {
                jsonResponse(false, 'Failed to create company.');
            }
            break;            
        case 'get_user_companies':
            try {
                $userId = getUserIdFromToken();
                if (!$userId) {
                    jsonResponse(false, 'User ID is required.');
                }
        
                $crudHandler = new CRUDHandler();
                $joins = [[
                    'table' => 'companies',
                    'on1' => 'user_company.company_id',
                    'operator' => '=',
                    'on2' => 'companies.id'
                ]];
        
                $conditions = ['user_company.user_id' => $userId];
                $columns = ['companies.id', 'companies.name', 'companies.created_at'];
        
                $companies = $crudHandler->read('user_company', $conditions, $columns, true, $joins);
                jsonResponse(true, 'Companies fetched successfully.', $companies);
            } catch (Exception $e) {
                jsonResponse(false, 'Error fetching companies.', null, ['error' => $e->getMessage()]);
            }
            break;
            
        case 'get_companies':
            try {
                $page = (int) ($_GET['page'] ?? 1);
                $limit = (int) ($_GET['limit'] ?? 25);
                $offset = ($page - 1) * $limit;
        
                $crudHandler = new CRUDHandler();
                $companies = $crudHandler->read(
                    'companies',
                    [],
                    ['id', 'name', 'logo', 'created_at'],
                    true,
                    [],
                    ['limit' => $limit, 'offset' => $offset],
                    ['orderBy' => ['created_at' => 'desc']],
                    true
                );
        
                $total = $crudHandler->count('companies');
        
                jsonResponse(true, 'Companies retrieved successfully.', [
                    'items' => $companies,
                    'pagination' => [
                        'total' => $total,
                        'page' => $page,
                        'limit' => $limit,
                    ]
                ]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error retrieving companies.', null, ['error' => $e->getMessage()]);
            }
            break;
        case 'update_company':
            try {
                $companyId = $data['company_id'] ?? null;
                $name = $data['name'] ?? null;
        
                if (!$companyId || !$name) {
                    jsonResponse(false, 'Company ID and name are required.');
                }
        
                $userId = getUserIdFromToken();
                $crudHandler = new CRUDHandler();
        
                // Kullanıcının admin olup olmadığını kontrol et
                $relation = $crudHandler->read('user_company', [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'role' => 'admin'
                ], ['id'], false);
        
                if (!$relation) {
                    jsonResponse(false, 'Unauthorized.');
                }
        
                $updated = $crudHandler->update('companies', ['name' => $name], ['id' => $companyId]);
                jsonResponse(true, 'Company updated.', ['updated' => $updated]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error updating company.', null, ['error' => $e->getMessage()]);
            }
            break;
        case 'delete_company':
            try {
                $companyId = $data['company_id'] ?? null;
                $userId = getUserIdFromToken();
        
                if (!$companyId) {
                    jsonResponse(false, 'Company ID is required.');
                }
        
                $crudHandler = new CRUDHandler();
        
                $relation = $crudHandler->read('user_company', [
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'role' => 'admin'
                ], ['id'], false);
        
                if (!$relation) {
                    jsonResponse(false, 'Unauthorized.');
                }
        
                $deleted = $crudHandler->delete('companies', ['id' => $companyId]);
                jsonResponse(true, 'Company deleted.', ['deleted' => $deleted]);
            } catch (Exception $e) {
                jsonResponse(false, 'Error deleting company.', null, ['error' => $e->getMessage()]);
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
