<?php

require_once __DIR__ . '/MailHandler.php';
require_once __DIR__ . '/CRUDHandlers.php';

class PasswordResetHandler {
    private $mailHandler;
    private $crud;

    public function __construct() {
        $this->mailHandler = new MailHandler();
        $this->crud = new CRUDHandler();
    }

    public function createResetRequest($email) {
        $user = $this->crud->read('users', ['email' => $email], fetchAll: false);
        if (!$user) {
            throw new \Exception('No user found with this email.');
        }

        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $this->crud->delete('password_resets', ['email' => $email]);
        $this->crud->create('password_resets', [
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);

        $resetLink = "https://seaofsea.com/public/api/reset_password.php?token=$token";
        $sendToClient =$this->sendResetEmail($email, $resetLink);

        return $sendToClient;
    }

    public function sendResetEmail($email, $resetLink) {
        $subject = "Password Reset Request";
        $body = "
            <h1>Password Reset Request</h1>
            <p>Click the link below to reset your password:</p>
            <a href=\"$resetLink\">Reset Password</a>
            <p>This link will expire in 1 hour.</p>
            <p><b>Note:</b> If you did not request a password reset, please ignore this email.</p>
            <p>Best regards,<br>The Sea of Sea Team</p>
        ";
        return $this->mailHandler->sendMail($email, $subject, $body);
    }

    public function verifyResetToken($token) {
        $resetRequest = $this->crud->read('password_resets', ['token' => $token], fetchAll: false);
        if (!$resetRequest) {
            throw new \Exception('Invalid or expired token.');
        }
        if (strtotime($resetRequest->expires_at) < time()) {
            throw new \Exception('The token has expired.');
        }
        return $resetRequest;
    }

    public function resetPassword($email, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $result = $this->crud->update('users', ['password' => $hashedPassword], ['email' => $email]);
        if ($result) {
            $this->crud->delete('password_resets', ['email' => $email]);
        }
        return $result;
    }
}
