<?php
require_once __DIR__ . '/DatabaseHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';
require_once __DIR__ . '/MailHandler.php';

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

        // Verileri al ve temizle
        $name = trim($data['name'] ?? '');
        $surname = trim($data['surname'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        // Validasyonlar
        if (empty($name)) {
            $errors[] = "Name is required.";
        }

        if (empty($surname)) {
            $errors[] = "Surname is required.";
        }

        if (empty($email)) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        } else {
            // Email benzersizlik kontrolü
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

        // Eğer hata yoksa kayıt işlemini yap
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

            if ($this->crud->create('users', $userData)) {
                $this->sendVerificationEmail($email, $verificationToken);
                return ['success' => true, 'message' => "Registration successful!"];
            } else {
                return ['success' => false, 'message' => "Failed to register user."];
            }
        }//Rate limit ile devam et

        // Hataları döndür
        return ['success' => false, 'errors' => $errors];
    }
    private function sendVerificationEmail($email, $token) {
        $subject = "Email Verification";
        $verificationLink = "http://localhost/api/verify_email.php?token=$token";

        $body = "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ";

        return $this->mailHandler->sendMail($email, $subject, $body);
    }   
}
?>
