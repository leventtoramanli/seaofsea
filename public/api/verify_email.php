<?php
require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../../lib/handlers/MailHandler.php';
require_once __DIR__ . '/../../lib/handlers/LoggerHandler.php';  // LoggerHandler'ı dahil ettik

use Dotenv\Dotenv;
use Monolog\Logger;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$crud = new CRUDHandler();
$mailHandler = new MailHandler();
$logger = new LoggerHandler();  // LoggerHandler sınıfını başlat

$token = $_GET['token'] ?? null;

$title = "Email Verification";
$message = "Invalid request.";
$messageType = "error";
$showResendButton = false;

if ($token) {
    $verification = $crud->read('verification_tokens', ['token' => $token]);

    if ($verification) {
        if (strtotime($verification['expires_at']) < time()) {
            $message = "The verification link has expired.";
            $showResendButton = true; // Token süresi dolmuşsa butonu göster
            $logger->log(Logger::WARNING, "Verification token expired", ['token' => $token]); // Hata loglama
        } else {
            $updated = $crud->update('users', ['is_verified' => 1, 'role_id' => 2], ['id' => $verification['user_id']]);
            if ($updated) {
                $crud->delete('verification_tokens', ['id' => $verification['id']]);
                $message = "Your email has been successfully verified!";
                $messageType = "success";
                $logger->log(Logger::INFO, "Email successfully verified", ['user_id' => $verification['user_id']]); // Başarı loglama
            } else {
                $message = "Failed to verify email. Please try again.";
                $logger->log(Logger::ERROR, "Email verification failed", ['user_id' => $verification['user_id']]); // Hata loglama
            }
        }
    } else {
        $message = "Invalid verification token.";
        $showResendButton = true; // Geçersiz token için butonu göster
        $logger->log(Logger::ERROR, "Invalid verification token", ['token' => $token]); // Hata loglama
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email'])) {
    $email = trim($_POST['email']);

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user = $crud->read('users', ['email' => $email]);

        if (!$user) {
            $message = "No user found with the provided email.";
            $messageType = "error";
            $logger->log(Logger::ERROR, "User not found for email", ['email' => $email]); // Hata loglama
        } else {
            $newToken = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Eski tokeni sil ve yeni tokeni ekle
            $crud->delete('verification_tokens', ['user_id' => $user['id']]);
            $crud->create('verification_tokens', [
                'user_id' => $user['id'],
                'token' => $newToken,
                'expires_at' => $expiresAt
            ]);

            // Doğrulama e-postasını gönder
            $verificationLink = "https://seaofsea.com/api/verify_email.php?token=$newToken";
            $mailHandler->sendMail($email, 'Email Verification', "
                <h1>Email Verification</h1>
                <p>Please click the link below to verify your email:</p>
                <a href=\"$verificationLink\">Verify Email</a>
            ");

            $message = "A new verification email has been sent to $email.";
            $messageType = "info";
            $logger->log(Logger::INFO, "Verification email resent", ['email' => $email]); // Başarı loglama
        }
    } else {
        $message = "Invalid email format.";
        $messageType = "error";
        $logger->log(Logger::ERROR, "Invalid email format", ['email' => $email]); // Hata loglama
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

        <?php if ($showResendButton): ?>
            <form method="POST">
                <input type="email" name="email" placeholder="Enter your email" required>
                <button type="submit" name="resend_email">Resend Verification Email</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
