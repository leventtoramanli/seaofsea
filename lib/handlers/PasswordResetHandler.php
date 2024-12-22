<?php
require_once 'DatabaseHandler.php';

class PasswordResetHandler {
    private $db;

    public function __construct($dbConnection) {
        $this->db = $dbConnection;
    }

    public function sendResetEmail($email, $resetLink) {
        $subject = "Password Reset Request";
        $message = "Click the link below to reset your password:\n\n$resetLink";
        $headers = "From: no-reply@yourdomain.com";

        return mail($email, $subject, $message, $headers);
    }

    public function verifyResetToken($token) {
        $query = "SELECT * FROM password_resets WHERE token=?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function resetPassword($email, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $query = "UPDATE users SET password=? WHERE email=?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ss", $hashedPassword, $email);
        return $stmt->execute();
    }
}
?>
