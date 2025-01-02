<?php
require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../../lib/handlers/PasswordResetHandler.php';
require_once __DIR__ . '/../../lib/handlers/LoggerHandler.php';  // LoggerHandler'ı dahil ettik

use Dotenv\Dotenv;
use Monolog\Logger;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$crud = new CRUDHandler();
$resetHandler = new PasswordResetHandler();
$logger = new LoggerHandler();  // LoggerHandler sınıfını başlat

$token = $_GET['token'] ?? null;

$title = "Reset Password";
$message = "Invalid request.";
$messageType = "error";
$showForm = false;

if ($token) {
    try {
        $resetRequest = $resetHandler->verifyResetToken($token);

        if ($resetRequest) {
            if (strtotime($resetRequest['expires_at']) < time()) {
                $message = "The password reset link has expired.";
                $logger->log(Logger::WARNING, "Password reset link expired", ['token' => $token]); // Log the expiration
            } else {
                $showForm = true;
                $logger->log(Logger::INFO, "Password reset token valid", ['token' => $token]); // Log successful token validation
            }
        } else {
            $message = "Invalid or expired token.";
            $logger->log(Logger::ERROR, "Invalid or expired reset token", ['token' => $token]); // Log invalid token
        }
    } catch (Exception $e) {
        $message = "An error occurred while verifying the token.";
        $logger->log(Logger::ERROR, "Error verifying reset token", ['exception' => $e]); // Log exception if any
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "error";
        $showForm = true;
        $logger->log(Logger::ERROR, "Password mismatch", ['new_password' => $newPassword, 'confirm_password' => $confirmPassword]); // Log password mismatch
    } elseif (strlen($newPassword) < 6) {
        $message = "Password must be at least 6 characters.";
        $messageType = "error";
        $showForm = true;
        $logger->log(Logger::ERROR, "Password too short", ['password_length' => strlen($newPassword)]); // Log password too short
    } else {
        try {
            $email = $resetRequest['email'];
            if ($resetHandler->resetPassword($email, $newPassword)) {
                $message = "Your password has been successfully reset.";
                $messageType = "success";

                // Şifre sıfırlama talebini sil
                $resetHandler->deleteResetRequest($email);
                $logger->log(Logger::INFO, "Password successfully reset", ['email' => $email]); // Log successful reset
            } else {
                $message = "Failed to reset the password. Please try again.";
                $messageType = "error";
                $logger->log(Logger::ERROR, "Failed to reset password", ['email' => $email]); // Log failed reset
            }
        } catch (Exception $e) {
            $message = "An error occurred while resetting the password.";
            $logger->log(Logger::ERROR, "Error resetting password", ['exception' => $e]); // Log any exception during reset
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="../../lib/css/mail.css">
</head>
<body>
    <div class="container">
        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></p>

        <?php if ($showForm): ?>
            <form method="POST">
                <input type="password" name="new_password" placeholder="Enter new password" required>
                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
