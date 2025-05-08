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

$publicEndpoints = [
    'login',
    'register',
    'reset_password',
    'reset_password_request',
    'refresh_token',
    'check_token'
];

$endpoint = $_GET['endpoint'] ?? null;
$tokenRequired = !in_array($endpoint, $publicEndpoints);

// Eğer token gerekiyorsa ve geçerli değilse, daha başta kes
if ($tokenRequired) {
    try {
        $token = getBearerToken();
        if (!$token) {
            http_response_code(401);
            jsonResponse(false, 'Bearer token is missing.', [], [], 401);
        }

        $userHandler = new UserHandler();
        $user = $userHandler->validateToken($token); // Token valid mi kontrol et
        if (!$user) {
            http_response_code(401);
            jsonResponse(false, 'Invalid or expired token.', [], ['error' => 'Expired or invalid'], 401);
        }
    } catch (Exception $e) {
        http_response_code(401);
        jsonResponse(false, 'Token validation failed.', [], ['error' => $e->getMessage()], 401);
    }
}



// JSON yanıt fonksiyonu
function jsonResponse($success, $message, $data = null, $errors = null, $code = 200, $showMessage = true) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data ?? [],
        'errors' => $errors ?? null,
        'showMessage' => $showMessage
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
        case 'get_user_permissions':
            require_once __DIR__ . '/../lib/handlers/PermissionHandler.php';
            try {
                $handler = new PermissionHandler();
                $companyId = $data['company_id'] ?? null;
        
                $permissions = $handler->getAllUserPermissions($companyId);
                jsonResponse(true, 'Permissions retrieved.', ['permissions' => $permissions]);
            } catch (Exception $e) {
                $logger->error("❌ get_user_permissions error: " . $e->getMessage());
                jsonResponse(false, 'An error occurred. Please try again later.');
            }
            break;
        case 'check_permission':
            require_once __DIR__ . '/../lib/handlers/PermissionHandler.php';
            $handler = new PermissionHandler();
        
            $permissionCode = $data['permission_code'] ?? null;
            $entityType = $data['entity_type'] ?? 'company';
            $entityId = $data['entity_id'] ?? null;
        
            if (!$permissionCode || !$entityId) {
                jsonResponse(false, 'Permission code and entity ID are required.');
            }
        
            try {
                $has = $handler->checkPermission($permissionCode, $entityId, $entityType);
                jsonResponse($has, $has ? 'Permission granted.' : 'Permission denied.');
            } catch (Exception $e) {
                jsonResponse(false, 'Error checking permission.', null, ['error' => $e->getMessage()]);
            }
            break;
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
        
                    jsonResponse(true, 'User info retrieved successfully.', $user, null, 200, false);
                } else {
                    jsonResponse(false, 'User not found.');
                }
            } catch (Exception $e) {
                http_response_code(401);
                $logger->error('Error retrieving user info.', ['exception' => $e]);
                jsonResponse(false, 'Error retrieving user info.', null, ['error' => $e->getMessage()], 401);
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
            jsonResponse(true, 'Users retrieved successfully.', $response, null, 200, false);
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
                jsonResponse($response['success'], $response['message'], $response['data'] ?? null, $response['errors'] ?? null, $response['statusCode'] ?? 200, false);
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
                jsonResponse($response['success'], $response['message'], $response['data'] ?? null, $response['errors'] ?? null, $response['statusCode'] ?? 200, false);
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
        
            jsonResponse(true, "Token is valid, expiry extended.", ["expiry" => $newExpiryTime], null, 200, false);
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
        case 'upload_image_general':
            try {
                $file = $_FILES['file'] ?? null;
                $userId = $data['user_id'] ?? $_POST['user_id'] ?? getUserIdFromToken();
                $folder = $data['folder'] ?? $_POST['folder'] ?? 'images/';
                $prefix = $data['prefix'] ?? $_POST['prefix'] ?? 'file';
                $maxSize = $data['max_size'] ?? $_POST['max_size'] ?? 1920;
                $meta = $data['meta'] ?? [];

                $thumb = isset($data['thumb']) ? filter_var($data['thumb'], FILTER_VALIDATE_BOOLEAN) : (isset($_POST['thumb']) ? filter_var($_POST['thumb'], FILTER_VALIDATE_BOOLEAN) : true);
                $thumbSize = isset($data['thumbSize']) ? (int)$data['thumbSize'] : (isset($_POST['thumbSize']) ? (int)$_POST['thumbSize'] : 128);
        
                if (!$file || !$userId || !$folder || !$prefix) {
                    getLogger()->error('❌ Upload Image General - Missing parameters.', [
                        'file' => $file,
                        'userId' => $userId,
                        'folder' => $folder,
                        'prefix' => $prefix
                    ]);
                    jsonResponse(false, 'File, userId, folder and prefix are required.');
                }
        
                if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                    getLogger()->error('❌ Upload Image General - File upload error.', [
                        'file' => $file
                    ]);
                    jsonResponse(false, 'File upload error.');
                }
        
                // ✅ Ana upload işlemi
                $uploadHandler = new App\Handlers\ImageUploadHandler($folder);
                $fileName = $uploadHandler->handleUploadWithPrefix($file, $userId, $prefix, $meta, $maxSize);
        
                if (!$fileName) {
                    getLogger()->error('❌ Upload Image General - File save failed.', [
                        'file' => $file,
                        'userId' => $userId,
                        'folder' => $folder
                    ]);
                    jsonResponse(false, 'Failed to upload image.');
                }
        
                // ✅ Thumb oluşturulacaksa
                if ($thumb) {
                    $baseDir = realpath(__DIR__ . '/../../seaofsea');
                    $originalPath = $baseDir . '/' . trim($folder, '/') . '/' . $fileName;
                    $thumbFolder = $baseDir . '/' . trim($folder, '/') . '/thumb/';

                    if (!is_dir($thumbFolder)) {
                        mkdir($thumbFolder, 0755, true);
                    }
        
                    $thumbPath = $thumbFolder . $fileName;
        
                    if (file_exists($originalPath)) {
                        // İlk önce thumb'a kopyala
                        if (!copy($originalPath, $thumbPath)) {
                            getLogger()->error('❌ Thumbnail copy failed.', [
                                'original' => $originalPath,
                                'thumb' => $thumbPath
                            ]);
                            jsonResponse(false, 'Failed to copy file for thumbnail.');
                        }
        
                        // Sonra thumbı yeniden boyutlandır
                        $thumbHandler = new App\Handlers\ImageUploadHandler(str_replace('/thumb/', '/', $folder));
                        $thumbHandler->resizeImage($thumbPath, $thumbSize);
        
                        getLoggerInfo()->info('✅ Thumbnail created successfully.', [
                            'thumbPath' => $thumbPath
                        ]);
                    } else {
                        getLogger()->error('❌ Original image not found for thumbnail creation.', [
                            'original' => $originalPath
                        ]);
                        jsonResponse(false, 'Original image not found for thumbnail.');
                    }
                }
        
                getLoggerInfo()->info('✅ Upload Image General - Image uploaded successfully.', [
                    'file_name' => $fileName,
                    'folder' => $folder
                ]);
        
                jsonResponse(true, 'Image uploaded successfully.', ['file_name' => $fileName]);
        
            } catch (Exception $e) {
                getLogger()->error('❌ Upload Image General Exception', ['exception' => $e]);
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
        case 'create_user_company':
        case 'get_user_companies':
        case 'get_user_company_role':
        case 'get_companies':
        case 'update_company':
        case 'get_company_employees':
        case 'get_company_followers':
        case 'get_company_detail':
        case 'get_company_types':
        case 'delete_company':
            require_once __DIR__ . '/../lib/handlers/CompanyHandler.php';
            $companyHandler = new CompanyHandler();
        
            switch ($endpoint) {
                case 'create_company':
                    $response = $companyHandler->createCompany($data);
                    break;
                case 'create_user_company':
                    $response = $companyHandler->createUserCompany($data);
                    break;
                case 'get_user_companies':
                    $response = $companyHandler->getUserCompanies($data);
                    break;
                case 'get_companies':
                    $response = $companyHandler->getAllCompanies($data);
                    break;
                case 'update_company':
                    $response = $companyHandler->updateCompany($data);
                    break;
                case 'delete_company':
                    $response = $companyHandler->deleteCompany($data);
                    break;
                case 'get_user_company_role':
                    $response = $companyHandler->getUserCompanyRole($data);
                    break;
                case 'get_company_followers':
                    $response = $companyHandler->getCompanyFollowers($data);
                    break;
                case 'get_company_employees':
                    $response = $companyHandler->getCompanyEmployees($data);
                    break;
                case 'get_company_detail':
                    $response = $companyHandler->getCompanyDetail($data);
                    break;
                case 'get_company_types':
                    $response = $companyHandler->getCompanyTypes($data);
                    break;
                case 'update_company':
                    jsonResponseFromArray($companyHandler->updateCompany($data));
                    break;                   
                default:
                    jsonResponse(false, 'Invalid endpoint.', [], [], 400);
                    return;
            }

            jsonResponse(
                $response['success'] ?? false,
                $response['message'] ?? '',
                (isset($response['data']) && is_array($response['data'])) ? $response['data'] : [],
                (isset($response['errors']) && is_array($response['errors'])) ? $response['errors'] : null,
                $response['statusCode'] ?? 200,
                $response['showMessage'] ?? true
            );
            
            break;
                
                
        default:
            jsonResponse(false, 'Invalid endpoint.');
    }
} catch (Exception $e) {
    // Hataları logla ve genel bir hata yanıtı gönder
    $logger->error('API Error', ['exception' => $e]);
    jsonResponse(false, 'An unexpected error occurred.', null, ['error' => $e->getMessage()]);
}