<?php
require_once __DIR__ . '/MailHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';

use Firebase\JWT\Key;
use Illuminate\Database\Capsule\Manager as Capsule;
use Firebase\JWT\JWT;
use Firebase\JWT\ExpiredException;

class UserHandler
{
    private $mailHandler;
    private $crud;
    private static $logger;

    private static $loggerInfo;

    public function __construct()
    {
        $this->mailHandler = new MailHandler();
        $this->crud = new CRUDHandler();
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }
        if (!self::$loggerInfo) {
            self::$loggerInfo = getLoggerInfo(); // Merkezi logger
        }
    }

    private function checkDatabase()
    {
        $checked = false;
        try {
            $checked = DatabaseHandler::testConnection();
        } catch (Exception $e) {
            self::$logger->error('Database connection error.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Database connection error.'];
        }
        return $checked;
    }

    public function getAllRoles() {
        return $this->crud->read('roles', fetchAll: true);
    }    

    public function validateAndRegisterUser($data)
    {
        self::$logger->info('Validation Input Data', ['data' => $data]);
        $errors = $this->validateUserData($data);
        if (!empty($errors)) {
            self::$logger->warning('Validation Errors', ['errors' => $errors]);
            return [
                'success' => false,
                'message' => $errors[0],
                'errors' => $errors
            ];
        }
        if (!$this->checkDatabase()) {
            return ['success' => false, 'message' => 'Database connection error.'];
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);
        $userData = [
            'name' => trim($data['name']),
            'surname' => trim($data['surname']),
            'email' => trim($data['email']),
            'password' => $hashedPassword,
            'is_verified' => 0,
            'role_id' => 3
        ];
        $verificationToken = bin2hex(random_bytes(16));
        try {
            $userId = $this->crud->create('users', $userData);
            $this->crud->create('verification_tokens', [
                'user_id' => $userId,
                'token' => $verificationToken,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]);

            if (!$this->sendVerificationEmail($data['email'], $verificationToken)) {
                throw new Exception('Verification email could not be sent.');
            }

            return ['success' => true, 'message' => 'User registered successfully. Please check your email for verification.'];
        } catch (Exception $e) {
            self::$logger->error('Registration error.', ['exception' => $e]);

            if (str_contains($e->getMessage(), 'email could not be sent')) {
                return ['success' => false, 'message' => 'Failed to send verification email.'];
            }

            return ['success' => false, 'message' => 'An error occurred during registration.', 'error' => $e->getMessage()];
        }
    }

    public function login($data)
    {
        $errors = $this->validateLoginData($data);
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Please fill in all required fields.', 'errors' => $errors];
        }

        if (!$this->checkDatabase()) {
            return ['success' => false, 'message' => 'Database connection error.'];
        }

        try {
            // Kullanıcıyı veritabanında ara
            $user = Capsule::table('users')->where('email', $data['email'])->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }
        } catch (Exception $e) {
            self::$logger->error('Database query error while fetching user.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Database query error.'];
        }

        // Şifre doğrulaması
        if (!$user || !password_verify($data['password'], $user->password)) {
            self::$logger->error('Login failed.', [
                'email' => $data['email'],
                'reason' => !$user ? 'User not found' : 'Incorrect password'
            ]);
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        // Access Token oluştur
        $jwt = $this->generateJWT($user); // Access Token oluştur
        $rememberMe = $data['rememberMe'] ?? false;
        $rememberTm = $rememberMe ? '+30 days' : '+1 hour';

        try {
            // Refresh Token kontrol et
            $refreshTokenData = Capsule::table('refresh_tokens')
                ->where('user_id', $user->id)
                ->where('device_info', $data['device_uuid'] ?? null) // Device UUID eşleşmesi
                ->first();
        
            if (!$refreshTokenData || strtotime($refreshTokenData->expires_at) < time()) {
                // Refresh Token yoksa veya süresi dolmuşsa, yeni bir refresh token oluştur
                $refreshToken = bin2hex(random_bytes(16));
                $expiresAt = date('Y-m-d H:i:s', strtotime($rememberTm));
                $deviceUUID = $data['device_uuid'] ?? null;
        
                // Refresh token'ı güncelle veya ekle
                Capsule::table('refresh_tokens')->updateOrInsert(
                    ['user_id' => $user->id, 'device_info' => $deviceUUID],
                    ['token' => $refreshToken, 'expires_at' => $expiresAt]
                );
            } else {
                // Mevcut Refresh Token'ı kullan
                $refreshToken = $refreshTokenData->token;
                $expiresAt = $refreshTokenData->expires_at;
            }
        } catch (Exception $e) {
            self::$logger->error('Failed to handle refresh token during login.', [
                'exception' => $e,
                'user_id' => $user->id,
            ]);
            return ['success' => false, 'message' => 'Failed to handle refresh token.'];
        }

        return [
            'success' => true,
            'message' => $user->is_verified ? 'Login successful.' : 'Please verify your email.',
            'data' => [
                'token' => $jwt, // Access Token
                'refresh_token' => $refreshToken, // Refresh Token
                'expires_at' => $expiresAt, // Süresi
                'is_verified' => $user->is_verified,
                'role' => (string) $user->role_id
            ]
        ];
    }

    public function updateUser($userId, $data)
    {
        try {
            $conditions = ['id' => $userId];
            $updateResult = $this->crud->update('users', $data, $conditions);

            if ($updateResult) {
                self::$logger->info('User updated successfully.', ['user_id' => $userId]);
                return ['success' => true, 'message' => 'User updated successfully.'];
            } else {
                throw new Exception('No rows affected during update.');
            }
        } catch (Exception $e) {
            self::$logger->error('User update failed.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Failed to update user.'];
        }
    }

    public function refreshAccessToken($refreshToken)
    {
        try {
            $refreshTokenData = Capsule::table('refresh_tokens')->where('token', $refreshToken)->first();

            if (!$refreshTokenData) {
                return ['success' => false, 'message' => 'Invalid refresh token.'];
            }

            if (strtotime($refreshTokenData->expires_at) < time()) {
                Capsule::table('refresh_tokens')->where('id', $refreshTokenData->id)->delete();
                return ['success' => false, 'message' => 'Refresh token has expired. Please log in again.'];
            }

            $user = Capsule::table('users')->where('id', $refreshTokenData->user_id)->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }

            $newAccessToken = $this->generateJWT($user);
            $newRefreshToken = bin2hex(random_bytes(32));
            $newRefreshTokenExpiration = date('Y-m-d H:i:s', strtotime('+30 days'));

            Capsule::table('refresh_tokens')
            ->where('id', $refreshTokenData->id)
            ->update(['token' => $newRefreshToken,'expires_at' => $newRefreshTokenExpiration]);
            return [
                'success' => true,
                'message' => 'Access token refreshed successfully.',
                'data' => [
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'expires_at' => $newRefreshTokenExpiration // Refresh token yeni süresi
                ]
            ];
        } catch (Exception $e) {
            self::$logger->error('Error during token refresh.', ['exception' => $e]);
            return ['success' => false, 'message' => 'An error occurred while refreshing token.'];
        }
    }

    public function getUserById($userId)
    {
        return $this->crud->read('users', ['id' => $userId]);
    }

    public static function getUserIdFromToken(): ?int
    {
        try {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

            if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                self::$logger->error('❌ Token header formatı geçersiz.');
                return null;
            }

            $token = $matches[1];
            $secret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET');

            if (!$secret) {
                self::$logger->error('❌ JWT_SECRET tanımsız!');
                return null;
            }

            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            self::$logger->info("✅ Token çözüldü. User ID: " . ($decoded->data->id ?? 'null'));

            return $decoded->data->id ?? null;
        }
        catch (ExpiredException $e) {
            http_response_code(401); // 🔥 Flutter burada refresh tetikler
            self::$logger->warning('⏰ Token süresi dolmuş: ' . $e->getMessage());
            return null;
        }
        catch (Exception $e) {
            self::$logger->error('❌ JWT decode hatası: ' . $e->getMessage());
            return null;
        }
    }

    public function deleteUser($userId)
    {
        try {
            $conditions = ['id' => $userId];
            $deleteResult = $this->crud->delete('users', $conditions);

            if ($deleteResult) {
                self::$logger->info('User deleted successfully.', ['user_id' => $userId]);
                return ['success' => true, 'message' => 'User deleted successfully.'];
            } else {
                throw new Exception('No rows affected during deletion.');
            }
        } catch (Exception $e) {
            self::$logger->error('User deletion failed.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Failed to delete user.'];
        }
    }

    public function getUsersWithRoles()
    {
        return $this->crud->read(
            table: 'users',
            columns: ['users.name', 'roles.name as role_name'],
            joins: [
                ['table' => 'roles', 'on1' => 'users.role_id', 'operator' => '=', 'on2' => 'roles.id']
            ],
            fetchAll: true
        );
    }

    public function sendVerificationEmail($email, $token)
    {
        $subject = "Email Verification";
        $verificationLink = "https://seaofsea.com/public/api/verify_email.php?token=$token";

        $body = "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ";

        return $this->mailHandler->sendMail($email, $subject, $body);
    }

    private function validateUserData($data)
    {
        $errors = [];
        if (empty($data['name']))
            $errors[] = "Name is required.";
        if (empty($data['surname']))
            $errors[] = "Surname is required.";
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required.";
        } else {
            $existingUser = Capsule::table('users')->where('email', $data['email'])->first();
            if ($existingUser) {
                $errors[] = "Email is already registered.";
            }
        }
        if (empty($data['password']) || !preg_match("/^(?=.*[a-z])(?=.*[A-Z]).{6,}$/", $data['password'])) {
            $errors[] = "Password must be at least 6 characters long, include one uppercase letter, and one lowercase letter.";
        }
        return $errors;
    }

    private function validateLoginData($data)
    {
        $errors = [];
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required.";
        }
        if (empty($data['password'])) {
            $errors[] = "Password is required.";
        }
        return $errors;
    }

    private function generateJWT($user)
    {
        $secretKey = $_ENV['JWT_SECRET'];
        $payload = [
            'iss' => 'https://seaofsea.com',
            'aud' => 'https://seaofsea.com',
            'iat' => time(),
            'exp' => time() + 600,
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'surname' => $user->surname,
                'role' => $user->role_id,
                'cover_image' => $user->cover_image,
                'is_verified' => $user->is_verified
            ]
        ];
        return JWT::encode($payload, $secretKey, 'HS256');
    }  

    public function cleanExpiredTokens() {
        try {
            $deletedCount = $this->crud->deleteExpiredRefreshTokens();
            if ($deletedCount > 0) {
                self::$logger->info('Expired refresh tokens cleaned up.', ['count' => $deletedCount]);
            }
        } catch (Exception $e) {
            self::$logger->error('Error while cleaning expired refresh tokens.', ['exception' => $e]);
        }
    }
    

    public function logout($refreshToken, $deviceUUID = null, $allDevices = false) {
    
        try {
            if ($allDevices) {
                // Tüm cihazlardan çıkış
                $refreshTokenData = Capsule::table('refresh_tokens')->where('token', $refreshToken)->first();
    
                if ($refreshTokenData) {
                    Capsule::table('refresh_tokens')->where('user_id', $refreshTokenData->user_id)->delete();
                }
            } else {
                // Sadece mevcut cihazdan çıkış
                Capsule::table('refresh_tokens')
                ->where('token', $refreshToken)
                ->where('device_info', $deviceUUID)
                ->delete();
                return ['success' => true, 'message' => 'Logout successful.'];
            }
        } catch (Exception $e) {
            self::$logger->error('Error while logging out.', ['exception' => $e]);
            return ['success' => false, 'message' => 'An error occurred while logging out.'];
        }
    }   
    public function validateToken($data)
    {
        $refreshToken = $data['refresh_token'] ?? null;

        if (!$refreshToken) {
            return ['success' => false, 'message' => 'Refresh token is required.'];
        }

        try {
            // Refresh token'ı kontrol et
            $tokenData = Capsule::table('refresh_tokens')
                ->where('token', $refreshToken)
                ->first();

            if ($tokenData && strtotime($tokenData->expires_at) > time()) {
                return ['success' => true, 'message' => 'Token is valid.'];
            } else {
                return ['success' => false, 'message' => 'Token is invalid or expired.'];
            }
        } catch (Exception $e) {
            self::$logger->error('Token validation error', ['exception' => $e]);
            return ['success' => false, 'message' => 'An error occurred while validating token.'];
        }
    }

    public function validateJWT($token)
    {
        try {
            $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            self::$logger->info('JWT validated successfully.', [
                'user_id' => $decoded->data->id,
                'email' => $decoded->data->email
            ]);
            return $decoded->data;
        } catch (Exception $e) {
            self::$logger->warning('JWT validation failed.', [
                'error' => $e->getMessage(),
                'token_snippet' => substr($token, 0, 10) . '...'
            ]);
            throw new Exception('Invalid or expired token.');
        }
    }
    public function getNotificationSettings($data)
    {
        $userId = $data['user_id'] ?? self::getUserIdFromToken();

        if (!is_numeric($userId) || $userId <= 0) {
            return ['success' => false, 'message' => 'User ID is required!'];
        }

        $settings = $this->crud->read('users', conditions: ['id' => $userId], fetchAll: false);

        if (!$settings) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        // stdClass yerine array döndür
        $settingsArray = is_object($settings) ? json_decode(json_encode($settings), true) : $settings;

        return [
            'success' => true,
            'message' => 'Notification settings retrieved successfully.',
            'data' => [
                'email_notifications' => $settingsArray['email_notifications'] ?? true,
                'app_notifications' => $settingsArray['app_notifications'] ?? true,
                'weekly_summary' => $settingsArray['weekly_summary'] ?? false
            ]
        ];
    }

    public function saveNotificationSettings($data)
    {
        $userId = $data['user_id'] ?? self::getUserIdFromToken();
        if (!$userId) {
            return ['success' => false, 'message' => 'User ID is required.'];
        }

        $updateData = [
            'email_notifications' => $data['email_notifications'] ?? true,
            'app_notifications' => $data['app_notifications'] ?? true,
            'weekly_summary' => $data['weekly_summary'] ?? false
        ];

        $update = $this->crud->update('users', $updateData, conditions: ['id' => $userId]);

        return $update
            ? ['success' => true, 'message' => 'Notification settings updated.']
            : ['success' => false, 'message' => 'Update failed.'];
    }

    public function changePassword($data)
{
    $userId = $this->getUserIdFromToken();
    if (!$userId) {
        return ['success' => false, 'message' => 'Unauthorized.'];
    }

    $currentPassword = $data['current_password'] ?? null;
    $newPassword = $data['new_password'] ?? null;

    if (!$currentPassword || !$newPassword) {
        return ['success' => false, 'message' => 'All fields are required.'];
    }

    $user = $this->crud->read('users', conditions: ['id' => $userId], fetchAll: false);

    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    // Parola doğrulama
    if (!password_verify($currentPassword, $user->password)) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    // Parolayı güncelle
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updated = $this->crud->update('users', ['password' => $hashedPassword], ['id' => $userId]);

    if ($updated) {
        return ['success' => true, 'message' => 'Password updated successfully.'];
    } else {
        return ['success' => false, 'message' => 'Failed to update password.'];
    }
}

}

function getUserIdFromToken() {
    $headers = getallheaders();
    $serverHeaders = $_SERVER;

    $authHeader = $headers['Authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!$authHeader) {
        getLogger()->error('🚨 JWT Hata: Authorization başlığı eksik!');
        http_response_code(401);
        jsonResponse(false, 'Authorization header missing.', null, null, 401);
    }

    if (strpos($authHeader, 'Bearer ') !== 0) {
        getLogger()->error('🚨 JWT Hata: Bearer formatı yanlış!');
        http_response_code(401);
        jsonResponse(false, 'Invalid Bearer format.', null, null, 401);
    }

    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
        return $decoded->data->id ?? null;
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Expired token') !== false) {
            getLogger()->error('🚨 JWT Expired: Token süresi dolmuş.', ['error' => $e->getMessage()]);

            // Eğer refresh token kullanılacaksa bu kısım kalabilir
            $refreshToken = getRefreshTokenFromDB($token);
            if ($refreshToken) {
                $newToken = refreshAccessToken($refreshToken);
                if ($newToken) {
                    return getUserIdFromToken(); // 🔁 Yeni token ile tekrar dene
                }
            }

            getLogger()->error('🚨 Refresh Token Hatası: Kullanıcı çıkış yapmalı.');
            http_response_code(401);
            jsonResponse(false, 'JWT expired, please reauthenticate.', null, null, 401);
        }

        getLogger()->error('🚨 JWT Decode Hata: ' . $e->getMessage());
        http_response_code(401);
        jsonResponse(false, 'Invalid token.', null, null, 401);
    }
}

function getRefreshTokenFromDB($oldAccessToken) {
    $userData = JWT::decode($oldAccessToken, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $refreshToken = Capsule::table('refresh_tokens')
        ->where('user_id', $userData->data->id)
        ->value('token');

    return $refreshToken ?: null;
}

