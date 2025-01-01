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
    public function validateAndRegisterUser($data) {
        $errors = [];

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
            $existingUser = $this->crud->read('users', ['email' => $email]);
            if (!empty($existingUser)) {
                $errors[] = "Email is already registered.";
            }
        }

        if (empty($password)) {
            $errors[] = "Password is required.";
        } elseif (!preg_match("/^(?=.*[a-z])(?=.*[A-Z]).{6,}$/", $password)) {
            $errors[] = "Password must be at least 6 characters long, include one uppercase letter, and one lowercase letter.";
        }

        if (empty($errors)) {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userData = [
                'name' => $name,
                'surname' => $surname,
                'email' => $email,
                'password' => $hashedPassword
            ];

            $verificationToken = bin2hex(random_bytes(16));
            $data['verification_token'] = $verificationToken;
            $data['is_verified'] = false;
            try {
                $this->db->autocommit(false);
    
                $userId = $this->crud->create('users', $userData, true);
    
                if (!$userId) {
                    throw new Exception('User registration failed.');
                }

                $verificationToken = bin2hex(random_bytes(16));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $tokenSaved = $this->crud->create('verification_tokens', [
                    'user_id' => $userId,
                    'token' => $verificationToken,
                    'expires_at' => $expiresAt
                ]);

                if (!$tokenSaved) { throw new Exception('Verification token could not be saved.'); }

                $emailSent = $this->sendVerificationEmail($userData['email'], $verificationToken);
    
                if (!$emailSent) {
                    throw new Exception('Verification email could not be sent.');
                }
    
                $this->db->commit();
    
                return ['success' => true];
            } catch (Exception $e) {
                $this->db->rollBack();
                return ['success' => false, 'Internal Server Error '/* . $e->getMessage()*/];
            }finally {
                $this->db->autocommit(true);
                return ['success' => true, 'User Registered, Please check your email for verification.'];
            }
        }

        return ['success' => false, 'errors' => $errors];
    }

    public function login($email, $password) {
        error_log("Login işlemi başladı.");
        // Kullanıcıyı veritabanından al
        try{
            $user = $this->crud->read('users', ['email' => $email]);
            if (!$user) {
                error_log("Kullanıcı bulunamadı: $email");
                return [
                    'success' => false,
                    'message' => 'Invalid email or password.'
                ];
            }
            error_log("Kullanıcı bulundu: " . json_encode($user));
        } catch (Exception $e) {
            error_log("Hata: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred, please try again later.'
            ];
        }
        // Şifre doğrulama
        if (!password_verify($password, $user['password'])) {
            error_log("Şifre doğrulaması başarısız.");
            return [
                'success' => false,
                'message' => 'Invalid email or password.'
            ];
        }
        error_log("Şifre doğrulaması başarılı.");

        // Kullanıcı doğrulama durumu
        $isVerified = $user['is_verified'] == 1;
        $role = $user['role'] ?? 'Guest';
        error_log("Kullanıcı doğrulama durumu: " . ($isVerified ? "Doğrulandı" : "Doğrulanmadı"));
        error_log("Kullanıcı rolü: $role");

        // JWT oluşturma
        try{
        $secretKey = $_ENV['JWT_SECRET'];
        if(empty($secretKey)){
            throw new Exception('JWT secret key is not set.');
        }
        $payload = [
            'iss' => 'https://seaofsea.com/public/api/login.php',
            'aud' => 'https://seaofsea.com',
            'iat' => time(),
            'exp' => time() + 3600,
            'data' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['name'],
                'role' => $role,
                'is_verified' => $isVerified
            ]
        ];
        $jwt = JWT::encode($payload, $secretKey, 'HS256');
        error_log("JWT oluşturuldu.");
        } catch (Exception $e) {
            error_log("JWT oluşturma hatası: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred, please try again later. JWT oluşturma hatası: ' . $e->getMessage()
            ];
        }
        return [
            'success' => true,
            'message' => $isVerified 
                ? 'Login successful.' 
                : 'You are logged in as an anonymous user. Please verify your email for full access.',
            'data' => [
                'token' => $jwt,
                'is_verified' => $isVerified,
                'role' => $role
            ]
        ];
    }
    
    public function sendVerificationEmail($email, $token) {
        $subject = "Email Verification";
        $verificationLink = "http://seaofsea.com/public/api/verify_email.php?token=$token";

        $body = "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ";

        return $this->mailHandler->sendMail($email, $subject, $body);
    }   
}
?>
