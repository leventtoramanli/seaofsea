<?php

require_once 'MailHandler.php';
use Illuminate\Database\Capsule\Manager as Capsule;

class PasswordResetHandler {
    private $mailHandler;
    private $crud;

    public function __construct() {
        // MailHandler ve CRUDHandler nesneleri
        $this->mailHandler = new MailHandler();
        $this->crud = new CRUDHandler();
    }

    // Şifre sıfırlama talebi oluştur
    public function createResetRequest($email) {
        // Kullanıcıyı kontrol et
        $user = $this->crud->read('users', ['email' => $email], fetchAll: false);
        if (!$user) {
            throw new \Exception('No user found with this email.');
        }
    
        // Token oluştur
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
        // Eski tokeni sil ve yeni tokeni oluştur
        $this->crud->delete('password_resets', ['email' => $email]); // Eski tokeni sil
        $this->crud->create('password_resets', [
            'email' => $email,
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
    
        return $token;
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
        // Tokeni kontrol et
        $resetRequest = $this->crud->read('password_resets', ['token' => $token], fetchAll: false);
    
        if (!$resetRequest) {
            throw new \Exception('Invalid or expired token.');
        }
    
        // Token süresini kontrol et
        if (strtotime($resetRequest->expires_at) < time()) {
            throw new \Exception('The token has expired.');
        }
    
        return $resetRequest;
    }
    

    // Şifre sıfırla
    public function resetPassword($email, $newPassword) {
        // Yeni şifreyi hashle
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $result = $this->crud->update('users', ['password' => $hashedPassword], ['email' => $email]);

        if ($result) {
            // Eski sıfırlama talebini sil
            $this->crud->delete('password_resets', ['email' => $email]);
        }

        return $result;
    }

    // Şifre sıfırlama talebini sil
    public function deleteResetRequest($email) {
        return $this->crud->delete('password_resets', ['email' => $email]);
    }
}
