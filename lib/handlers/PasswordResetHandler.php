<?php
require_once 'DatabaseHandler.php';
require_once 'CRUDHandlers.php';
require_once 'MailHandler.php';

class PasswordResetHandler {
    private $crud;
    private $mailHandler;

    public function __construct($dbConnection) {
        $this->crud = new CRUDHandler($dbConnection);
        $this->mailHandler = new MailHandler();
    }

    // Şifre sıfırlama talebini sil
    public function deleteResetRequest($email) {
        return $this->crud->delete('password_resets', ['email' => $email]);
    }

    // Şifre sıfırlama e-postası gönder
    public function sendResetEmail($email, $resetLink) {
        $subject = "Password Reset Request";
        $body = "
            <h1>Password Reset Request</h1>
            <p>Click the link below to reset your password:</p>
            <a href=\"$resetLink\">Reset Password</a>
        ";
        return $this->mailHandler->sendMail($email, $subject, $body);
    }

    // Şifre sıfırlama tokenini doğrula
    public function verifyResetToken($token) {
        return $this->crud->read(
            table: 'password_resets',
            searchs: ['token' => $token]
        );
    }

    // Şifre sıfırla
    public function resetPassword($email, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        return $this->crud->update(
            table: 'users',
            data: ['password' => $hashedPassword],
            conditions: ['email' => $email]
        );
    }

    // Şifre sıfırlama talebi oluştur
    public function createResetRequest($email) {
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // Eski tokeni sil ve yeni tokeni oluştur
        $this->crud->delete('password_resets', ['email' => $email]);
        $result = $this->crud->create(
            'password_resets',
            [
                'email' => $email,
                'token' => $token,
                'expires_at' => $expiresAt
            ]
        );

        return $result ? $token : false;
    }
}
