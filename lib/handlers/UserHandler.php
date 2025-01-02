<?php

require_once __DIR__ . '/MailHandler.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use Firebase\JWT\JWT;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class UserHandler {
    private $mailHandler;
    private $crud;
    private static $logger;

    public function __construct() {
        $this->mailHandler = new MailHandler();
        $this->crud = new CRUDHandler(); // CRUDHandler ile uyumlu hale geldi
        if (!self::$logger) {
            self::$logger = new Logger('database');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/database.log', Logger::ERROR));
        }
    }

    // Kullanıcı kaydı ve doğrulama
    public function validateAndRegisterUser($data) {
        $errors = [];

        // Alan doğrulama
        $name = trim($data['name'] ?? '');
        $surname = trim($data['surname'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($name)) $errors[] = "Name is required.";
        if (empty($surname)) $errors[] = "Surname is required.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required.";
        } else {
            $existingUser = Capsule::table('users')->where('email', $email)->first();
            if ($existingUser) {
                $errors[] = "Email is already registered.";
            }
        }
        if (empty($password) || !preg_match("/^(?=.*[a-z])(?=.*[A-Z]).{6,}$/", $password)) {
            $errors[] = "Password must be at least 6 characters long, include one uppercase letter, and one lowercase letter.";
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Kullanıcı oluştur
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $userData = [
            'name' => $name,
            'surname' => $surname,
            'email' => $email,
            'password' => $hashedPassword,
            'is_verified' => 0,
            'role_id' => 3
        ];

        $verificationToken = bin2hex(random_bytes(16));
        try {
            // Kullanıcı ve doğrulama tokeni ekle
            $userId = $this->crud->create('users', $userData);
            $this->crud->create('verification_tokens', [
                'user_id' => $userId,
                'token' => $verificationToken,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]);

            // Doğrulama emaili gönder
            if (!$this->sendVerificationEmail($email, $verificationToken)) {
                throw new Exception('Verification email could not be sent.');
            }

            return ['success' => true, 'message' => 'User Registered. Please check your email for verification.'];
        } catch (Exception $e) {
            $this->logError($e);
            return ['success' => false, 'message' => 'An error occurred during registration.'];
        }
    }

    // Kullanıcı giriş işlemi
    public function login($data) {
        $errors = [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required.";
        }
        if (empty($password)) {
            $errors[] = "Password is required.";
        }
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Kullanıcı kontrolü
        $user = Capsule::table('users')->where('email', $email)->first();
        if (!$user || !password_verify($password, $user->password)) {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        // JWT oluştur
        $secretKey = $_ENV['JWT_SECRET'];
        $payload = [
            'iss' => 'https://seaofsea.com',
            'aud' => 'https://seaofsea.com',
            'iat' => time(),
            'exp' => time() + 3600,
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role_id,
                'is_verified' => $user->is_verified
            ]
        ];
        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        return [
            'success' => true,
            'message' => $user->is_verified ? 'Login successful.' : 'Please verify your email.',
            'data' => [
                'token' => $jwt,
                'is_verified' => $user->is_verified,
                'role' => $user->role_id
            ]
        ];
    }

    // Kullanıcı ve rollerini getir
    public function getUsersWithRoles() {
        return $this->crud->read(
            table: 'users',
            columns: ['users.name', 'roles.name as role_name'],
            joins: [
                ['table' => 'roles', 'on1' => 'users.role_id', 'operator' => '=', 'on2' => 'roles.id']
            ],
            fetchAll: true
        );
    }

    // Doğrulama emaili gönder
    public function sendVerificationEmail($email, $token) {
        $subject = "Email Verification";
        $verificationLink = "https://seaofsea.com/public/api/verify_email.php?token=$token";

        $body = "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ";

        return $this->mailHandler->sendMail($email, $subject, $body);
    }

    // Hata loglama
    private function logError($exception) {
        self::$logger->error('UserHandler Error: ' . $exception->getMessage(), ['exception' => $exception]);
    }
}
