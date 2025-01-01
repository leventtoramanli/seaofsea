<?php
require_once __DIR__ . '/DatabaseHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';
require_once __DIR__ . '/MailHandler.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;

class UserHandler {
    private $db;
    private $crud;
    private $mailHandler;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
        $this->crud = new CRUDHandler($dbConnection);
        $this->mailHandler = new MailHandler();
    }

    // Kullanıcı kaydı ve doğrulama
    public function validateAndRegisterUser($data) {
        $errors = [];

        // Alanları doğrula
        $name = trim($data['name'] ?? '');
        $surname = trim($data['surname'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($name)) {$errors[] = "Name is required.";}
        if (empty($surname)) {$errors[] = "Surname is required.";}
        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        } else {
            // Email kontrolü
            $existingUser = $this->crud->read('users', ['email' => $email]);
            if ($existingUser) {
                $errors[] = "Email is already registered.";
            }
        }
        if (empty($password)) {
            $errors[] = "Password is required.";
        } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z]).{6,}$/", $password)) {
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
            'role_id' => 3 // Default role: Guest
        ];

        $verificationToken = bin2hex(random_bytes(16));
        try {
            $this->db->begin_transaction();

            $userId = $this->crud->create('users', $userData, true);
            if (!$userId) {
                throw new Exception('User registration failed.');
            }

            // Doğrulama tokeni oluştur
            $this->crud->create('verification_tokens', [
                'user_id' => $userId,
                'token' => $verificationToken,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ]);

            // Doğrulama emaili gönder
            if (!$this->sendVerificationEmail($email, $verificationToken)) {
                throw new Exception('Verification email could not be sent.');
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'User Registered. Please check your email for verification.'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // Kullanıcı giriş işlemi
    public function login($data) {
        $errors = [];
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        if (empty($password)) {
            $errors[] = "Password is required.";
        }
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Kullanıcıyı kontrol et
        $user = $this->crud->read('users', ['email' => $email]);
        if (!$user || !password_verify($password, $user['password'])) {
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
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $user['role_id'],
                'is_verified' => $user['is_verified']
            ]
        ];
        $jwt = JWT::encode($payload, $secretKey, 'HS256');

        return [
            'success' => true,
            'message' => $user['is_verified'] ? 'Login successful.' : 'Please verify your email.',
            'data' => [
                'token' => $jwt,
                'is_verified' => $user['is_verified'],
                'role' => $user['role_id']
            ]
        ];
    }

    // Kullanıcı ve rollerini getir
    public function getUsersWithRoles() {
        return $this->crud->read(
            table: 'users',
            columns: ['users.name', 'roles.name AS role_name'],
            joins: [
                [
                    'type' => 'INNER',
                    'table' => 'roles',
                    'on' => 'users.role_id = roles.id'
                ]
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
}
