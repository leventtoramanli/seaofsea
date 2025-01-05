<?php
require_once __DIR__ . '/MailHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Firebase\JWT\JWT;

class UserHandler {
    private $mailHandler;
    private $crud;
    private static $logger;

    public function __construct() {
        $this->mailHandler = new MailHandler();
        $this->crud = new CRUDHandler();
        if (!self::$logger) {
            self::$logger = getLogger(); // Merkezi logger
        }
    }

    private function checkDatabase() {
        $checked = false;
        try {
            $checked = DatabaseHandler::testConnection();
        } catch (Exception $e) {
            self::$logger->error('Database connection error.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Database connection error.'];
        }
        return $checked;
    }

    public function validateAndRegisterUser($data) {
        self::$logger->info('Validation Input Data', ['data' => $data]);
        $errors = $this->validateUserData($data);
        if (!empty($errors)) {
            self::$logger->warning('Validation Errors', ['errors' => $errors]);
            return [
                'success' => false,
                'message' => $errors[0], // İlk hatayı kullanıcıya mesaj olarak göster
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

    public function login($data) {
        $errors = $this->validateLoginData($data);
        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Please fill in all required fields.', 'errors' => $errors];
        }
        if (!$this->checkDatabase()) {
            return ['success' => false, 'message' => 'Database connection error.'];
        }
        try {
            $user = Capsule::table('users')->where('email', $data['email'])->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }
        } catch (Exception $e) {
            self::$logger->error('Database query error.', ['exception' => $e]);
            return ['success' => false, 'message' => 'Database query error.'];
        }
        if (!$user || !password_verify($data['password'], $user->password)) {
            self::$logger->warning('Login failed.', [
                'email' => $data['email'],
                'reason' => !$user ? 'User not found' : 'Incorrect password'
            ]);
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }

        $jwt = $this->generateJWT($user);

        return [
            'success' => true,
            'message' => $user->is_verified ? 'Login successful.' : 'Please verify your email.',
            'data' => [
                'token' => $jwt,
                'is_verified' => $user->is_verified,
                'role' => (string) $user->role_id
            ]
        ];
    }

    public function updateUser($userId, $data) {
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

    public function refreshAccessToken($refreshToken) {
        try {
            $refreshTokenData = Capsule::table('refresh_tokens')->where('token', $refreshToken)->first();
    
            if (!$refreshTokenData) {
                return ['success' => false, 'message' => 'Invalid refresh token.'];
            }
    
            if (strtotime($refreshTokenData->expires_at) < time()) {
                Capsule::table('refresh_tokens')->where('id', $refreshTokenData->id)->delete();
                return ['success' => false, 'message' => 'Refresh token has expired.'];
            }
    
            $user = Capsule::table('users')->where('id', $refreshTokenData->user_id)->first();
            if (!$user) {
                return ['success' => false, 'message' => 'User not found.'];
            }
    
            $newAccessToken = $this->generateJWT($user);
    
            return [
                'success' => true,
                'message' => 'Access token refreshed successfully.',
                'data' => ['access_token' => $newAccessToken]
            ];
        } catch (Exception $e) {
            self::$logger->error('Error during token refresh.', ['exception' => $e]);
            return ['success' => false, 'message' => 'An error occurred while refreshing token.'];
        }
    }
    
    public function deleteUser($userId) {
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

    private function validateUserData($data) {
        $errors = [];
        if (empty($data['name'])) $errors[] = "Name is required.";
        if (empty($data['surname'])) $errors[] = "Surname is required.";
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

    private function validateLoginData($data) {
        $errors = [];
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required.";
        }
        if (empty($data['password'])) {
            $errors[] = "Password is required.";
        }
        return $errors;
    }

    private function generateJWT($user) {
        $secretKey = $_ENV['JWT_SECRET'];
        $payload = [
            'iss' => 'https://seaofsea.com',
            'aud' => 'https://seaofsea.com',
            'iat' => time(),
            'exp' => time() + 60,
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role_id,
                'is_verified' => $user->is_verified
            ]
        ];

        $token = JWT::encode($payload, $secretKey, 'HS256');
        self::$logger->info('JWT generated for user.', [
            'user_id' => $user->id,
            'email' => $user->email
        ]);

        return $token;
    }

    public function validateJWT($token) {
        try {
            $algorithms = ['HS256'];
            $decoded = JWT::decode($token, $_ENV['JWT_SECRET'], $algorithms);
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
}
