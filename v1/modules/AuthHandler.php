<?php

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/JWT.php';
require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../core/Crud.php';
class AuthHandler
{
    private static Crud $crud;
    public static function init(?int $userId = null): void
    {
        self::$crud = new Crud($userId);
    }
    public static function login(array $params): array
    {
        self::init(null);
        $config = require __DIR__ . '/../config/config.php';

        // Giriş verilerini al
        $email = trim($params['email'] ?? '');
        $password = trim($params['password'] ?? '');
        $deviceUUID = trim($params['device_uuid'] ?? '');
        $deviceName = $params['device_name'] ?? null;
        $platform = $params['platform'] ?? null;
        $osVersion = $params['os_version'] ?? null;
        $appVersion = $params['app_version'] ?? null;
        $rememberMe = isset($params['remember_me']) && $params['remember_me'] ? 1 : 0;


        if (empty($email) || empty($password)) {
            Response::error("Email and password are required", 422);
        }

        // Kullanıcıyı veritabanından çek
        $user = self::$crud->read('users', ['email' => $email], false);
        if (!$user) {
            Response::error("User not found", 404);
        }

        // Şifre kontrolü
        if (!password_verify($password, $user['password'])) {
            Response::error("Email or password is incorrect", 401);
        }
        $expiresAt = time() + $config['jwt']['expiration'];
        // Token oluştur
        $token = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'exp' => $expiresAt
        ], $config['jwt']['secret'], $config['jwt']['expiration']);

        // Refresh Token oluştur
        $refreshToken = bin2hex(random_bytes(32));

        // Cihaz kaydı
        if (!empty($deviceUUID)) {

            $existing = self::$crud->read('user_devices', [
                'user_id' => $user['id'],
                'device_uuid' => $deviceUUID
            ], false);

            $deviceData = [
                'user_id' => $user['id'],
                'device_uuid' => $deviceUUID,
                'device_name' => $deviceName,
                'platform' => $platform,
                'os_version' => $osVersion,
                'app_version' => $appVersion,
                'refresh_token' => $refreshToken,
                'is_active' => 1,
                'remember_me' => $rememberMe,
                'last_used_at' => date('Y-m-d H:i:s'),
                'created_at' => $existing ? $existing['created_at'] : date('Y-m-d H:i:s'),
                'expires_at' => $rememberMe
                                ? date('Y-m-d H:i:s', strtotime('+30 days'))
                                : date('Y-m-d H:i:s', strtotime('+1 day')),
            ];

            if ($existing) {
                self::$crud->update('user_devices', $deviceData, [
                    'user_id' => $user['id'],
                    'device_uuid' => $deviceUUID
                ]);
            } else {
                self::$crud->create('user_devices', $deviceData);
            }
        }

        // Yanıtla
        return [
            'token' => $token,
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role_id'] ?? null,
                'is_verified' => $user['is_verified']
            ]
        ];
    }

    public static function refresh_token(array $params): array
    {
        $refreshToken = $params['refresh_token'] ?? null;
        $deviceUUID = $params['device_uuid'] ?? null;

        if (!$refreshToken || !$deviceUUID) {
            return ['success' => false, 'message' => 'Refresh token or device UUID is required.'];
        }

        // userId henüz bilinmiyor, sorguyu yapıyoruz
        $tempCrud = new Crud(null);
        $record = $tempCrud->read('refresh_tokens', [
            'refresh_token' => $refreshToken,
            'device_uuid' => $deviceUUID
        ], false);

        if (!$record) {
            return ['success' => false, 'message' => 'Refresh token not found.'];
        }

        // userId'yi bulduk, artık güvenli Crud kullanabiliriz
        self::init($record['user_id']);

        $user = self::$crud->read('users', ['id' => $record['user_id']], false);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        $newJwt = JWT::encode(['user_id' => $user['id']], $_ENV['JWT_SECRET']);
        $newRefresh = bin2hex(random_bytes(32));

        self::$crud->update('refresh_tokens', [
            'id' => $record['id']
        ], [
            'refresh_token' => $newRefresh,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'success' => true,
            'data' => [
                'token' => $newJwt,
                'refresh_token' => $newRefresh,
                'user' => $user
            ]
        ];
    }

    public static function validate_token(array $params): array
    {
        $token = $params['token'] ?? null;

        if (!$token) {
            return ['valid' => false, 'message' => 'Token missing'];
        }

        try {
            $decoded = JWT::decode($token, $_ENV['JWT_SECRET']);
            if (!isset($decoded['user_id'])) {
                return ['valid' => false, 'message' => 'Invalid token structure'];
            }

            self::init($decoded['user_id']);
            $user = self::$crud->read('users', ['id' => $decoded['user_id']], false);

            if (!$user) {
                return ['valid' => false, 'message' => 'User not found'];
            }

            return [
                'valid' => true,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role_id'] ?? null
                ]
            ];
        } catch (Exception $e) {
            return ['valid' => false, 'message' => 'Token verification failed'];
        }
    }
    public static function logout(array $params): array
    {
        $refreshToken = $params['refresh_token'] ?? null;
        $deviceUUID = $params['device_uuid'] ?? null;
        $allDevices = $params['all_devices'] ?? false;

        if (!$refreshToken) {
            return ['success' => false, 'message' => 'Missing refresh token'];
        }

        // Token'dan kullanıcıyı bul
        $record = Crud::getInstance()->read('user_devices', [
            'refresh_token' => $refreshToken
        ], false);

        if (!$record) {
            return ['success' => false, 'message' => 'Invalid refresh token'];
        }

        $userId = $record['user_id'];
        $crud = Crud::getInstance($userId);

        if ($allDevices) {
            // Tüm cihazlardan çıkış
            $deleted = $crud->update('user_devices', [
                'is_active' => 0,
                'refresh_token' => null
            ], ['user_id' => $userId]);

            return [
                'success' => true,
                'message' => 'Logout from all devices successful',
                'updated' => $deleted
            ];
        }

        // Tek cihazdan çıkış
        if (!$deviceUUID) {
            return ['success' => false, 'message' => 'Missing device UUID'];
        }

        $crud->update('user_devices', [
            'is_active' => 0,
            'refresh_token' => null
        ], [
            'user_id' => $userId,
            'device_uuid' => $deviceUUID
        ]);

        return ['success' => true, 'message' => 'Logout successful'];
    }

    public static function anonymous_login(array $params): array
    {
        $deviceUUID = $params['device_uuid'] ?? null;
        $deviceName = $params['device_name'] ?? 'Unknown Device';
        $platform = $params['platform'] ?? 'unknown';

        if (!$deviceUUID) {
            return ['success' => false, 'message' => 'Cihaz UUID gerekli.'];
        }

        // CRUD örneği oluştur
        self::init(null); // kullanıcı henüz yok

        // Daha önce kayıtlı mı?
        $existingDevice = self::$crud->read('user_devices', ['device_uuid' => $deviceUUID], false);

        if ($existingDevice) {
            $userId = $existingDevice['user_id'];
            $user = self::$crud->read('users', ['id' => $userId], false);
            if (!$user) {
                return ['success' => false, 'message' => 'Kullanıcı bulunamadı.'];
            }
            $config = require __DIR__ . '/../config/config.php';
            $token = JWT::encode([
                'user_id' => $user['id'],
                'email' => $user['email']
            ], $config['jwt']['secret'], $config['jwt']['expiration']);


            return [
                'success' => true,
                'message' => 'Anonim kullanıcı bulundu.',
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'role' => $user['role_id'] ?? null,
                ]
            ];
        }

        // Yeni kullanıcı oluştur
        $newUserData = [
            'name' => 'Anonymous',
            'surname' => 'User',
            'email' => self::generateAnonymousEmail(),
            'password' => 'anonymous',
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $userId = self::$crud->create('users', $newUserData);

        if (!$userId) {
            return ['success' => false, 'message' => 'Kullanıcı oluşturulamadı.'];
        }

        // Device kaydı
        self::init($userId);
        $deviceCreated = self::$crud->create('user_devices', [
            'user_id' => $userId,
            'device_uuid' => $deviceUUID,
            'device_name' => $deviceName,
            'platform' => $platform,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$deviceCreated) {
            return ['success' => false, 'message' => 'Cihaz kaydedilemedi.'];
        }
        $config = require __DIR__ . '/../config/config.php';
        $token = JWT::encode([
            'user_id' => $user['id'],
            'email' => $user['email']
        ], $config['jwt']['secret'], $config['jwt']['expiration']);


        return [
            'success' => true,
            'message' => 'Yeni anonim kullanıcı oluşturuldu.',
            'token' => $token,
            'user' => [
                'id' => $userId,
                'name' => 'Anonymous',
                'email' => $newUserData['email'],
                'role' => null,
            ]
        ];
    }

    private static function generateAnonymousEmail(): string
    {
        return 'anon_' . uniqid() . '@seaofsea.com';
    }

    public static function register($params)
    {
        $config = require __DIR__ . '/../config/config.php';
        $required = ['name', 'surname', 'email', 'password', 'device_uuid'];
        foreach ($required as $key) {
            if (empty($params[$key])) {
                return Response::error("Missing required field: $key", 400);
            }
        }

        $name = trim($params['name']);
        $surname = trim($params['surname']);
        $email = strtolower(trim($params['email']));
        $password = $params['password'];
        $deviceUuid = $params['device_uuid'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error("Invalid email format", 400);
        }
        self::init();
        $existing = self::$crud->read('users', ['email' => $email], false);
        if ($existing) {
            return Response::error("Email already registered", 409);
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        self::init(null);
        $userData = [
            'name' => $name,
            'surname' => $surname,
            'email' => $email,
            'password' => $hashedPassword,
            'is_verified' => 0,
            'role_id' => 3,
            'created_at' => date('Y-m-d H:i:s')
        ];
        $userId = self::$crud->create('users', $userData);

        if (!$userId) {
            return Response::error("User creation failed", 500);
        }

        // Email doğrulama tokenı oluştur
        $verificationToken = bin2hex(random_bytes(16));
        self::$crud->create('verification_tokens', [
            'user_id' => $userId,
            'token' => $verificationToken,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
        ]);

        // Mail gönderimi
        if (strtolower($name) !== 'anonymous' && !empty($email) && strpos($email, '@') !== false) {
            if (!self::sendVerificationEmail($email, $verificationToken)) {
                return Response::error("Failed to send verification email.", 500);
            }
        }
        // JWT ve refresh token
        $jwtToken = JWT::encode([
            'user_id' => $userId,
            'email' => $email,
            'name' => $name,
            'surname' => $surname
        ], $config['jwt']['secret'], $config['jwt']['expiration']);


        $refreshToken = bin2hex(random_bytes(32));
        $expiresAt = time() + $config['jwt']['expiration'];
        $existing = self::$crud->read('user_devices', [
            'user_id' => $userId,
            'device_uuid' => $deviceUuid
        ], false);

        self::$crud->update('user_devices', ['is_active' => 0], [
            'device_uuid' => $deviceUuid
        ]);
        $deviceData = [
            'user_id' => $userId,
            'device_uuid' => $deviceUuid,
            'refresh_token' => $refreshToken,
            'is_active' => 1,
            'last_used_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'device_name' => $params['device_name'] ?? null,
            'platform' => $params['platform'] ?? null,
            'os_version' => $params['os_version'] ?? null,
            'app_version' => $params['app_version'] ?? null,
        ];

        if ($existing) {
            self::$crud->update('user_devices', $deviceData, [
                'user_id' => $userId,
                'device_uuid' => $deviceUuid
            ]);
        } else {
            $deviceData['created_at'] = date('Y-m-d H:i:s');
            self::$crud->create('user_devices', $deviceData);
        }
        return Response::success([
            'token' => $jwtToken,
            'refresh_token' => $refreshToken,
            'user' => [
                'id' => $userId,
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
            ]
        ], "Registration successful. Please verify your email.");
    }

    private static function sendVerificationEmail($email, $token)
    {
        $subject = "Email Verification";
        $verificationLink = "https://seaofsea.com/public/api/verify_email.php?token=$token";
        $body = "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ";

        $mailer = new MailHandler();
        return $mailer->sendMail($email, $subject, $body);
    }
    public static function resetPasswordRequest(array $params): void
    {
        $config = require __DIR__ . '/../config/config.php';
        $email = $params['email'] ?? null;
        if (!$email) {
            Response::error("Email is required.", 400);
            return;
        }

        $crud = new Crud();
        $user = $crud->read('users', ['email' => $email], fetchAll: false);

        if (!$user || !isset($user['email'])) {
            Response::error("No user found with this email.", 404);
            return;
        }

        // Eski tokenları temizle
        $crud->delete('password_resets', ['email' => $email]);

        // Yeni token üret
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Kaydet
        $inserted = $crud->create('password_resets', [
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        if (!$inserted) {
            Response::error("Failed to store reset token.", 500);
            return;
        }

        // E-posta gönderimi
        try {
            $resetLink = $config['app']['url'] . "public/api/reset_password.php?token=$token";

            $subject = "Password Reset Request";
            $body = "
                <h1>Password Reset</h1>
                <p>You requested a password reset for your SeaOfSea account.</p>
                <p><a href=\"$resetLink\">Click here to reset your password</a></p>
                <p>This link will expire in 15 minutes.</p>
                <p>If you did not request this, please ignore this email.</p>
            ";

            $mailer = new MailHandler();
            $sent = $mailer->sendMail($email, $subject, $body);

            if (!$sent) {
                Response::error("Failed to send reset email.", 500);
                return;
            }

            Logger::info("Reset email sent to $email");
            Response::success("Password reset email sent successfully to $email.");
        } catch (Exception $e) {
            Logger::error("Reset email error", ['error' => $e->getMessage()]);
            Response::error("Unexpected error during email sending.", 500);
        }
    }
}
