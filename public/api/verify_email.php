<?php

require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../../lib/handlers/MailHandler.php';


$crud = new CRUDHandler();
$mailHandler = new MailHandler();
$logger = getLogger();

$token = $_GET['token'] ?? null;

$title = "Email Verification";
$message = "Invalid request.";
$messageType = "error";
$showResendButton = false;

if ($token) {
    try {
        $verification = $crud->read('verification_tokens', ['token' => $token], fetchAll: false);

        if ($verification) {
            if (strtotime($verification->expires_at) < time()) {
                $crud->delete('verification_tokens', ['id' => $verification->id]);
                $message = "The verification link has expired.";
                $messageType = "error";
                $showResendButton = true;
                $logger->warning("Verification token expired.", ['token' => substr($token, 0, 10) . '...']);
            } else {
                $updated = $crud->update('users', ['is_verified' => 1, 'role_id' => 2], ['id' => $verification->user_id]);
                if ($updated) {
                    $crud->delete('verification_tokens', ['id' => $verification->id]);
                    $message = "Your email has been successfully verified!";
                    $messageType = "success";
                    $logger->info("Email successfully verified.", ['user_id' => $verification->user_id]);
                } else {
                    $message = "Failed to verify email. Please try again.";
                    $messageType = "error";
                    $logger->error("Email verification failed.", ['user_id' => $verification->user_id]);
                }
            }
        } else {
            $message = "Invalid verification token.";
            $showResendButton = true;
            $logger->error("Invalid verification token.", ['token' => substr($token, 0, 10) . '...']);
        }
    } catch (Exception $e) {
        $message = "An error occurred while processing your request.";
        $messageType = "error";
        $logger->error("Error during email verification.", ['exception' => $e]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email'])) {
    $email = trim($_POST['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $user = $crud->read('users', ['email' => $email], fetchAll: false);
            if (!$user) {
                $message = "No user found with the provided email.";
                $messageType = "error";
                $logger->error("User not found for email.", ['email' => $email]);
            } else {
                $newToken = bin2hex(random_bytes(16));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $crud->delete('verification_tokens', ['user_id' => $user->id]);
                $crud->create('verification_tokens', [
                    'user_id' => $user->id,
                    'token' => $newToken,
                    'expires_at' => $expiresAt
                ]);

                $verificationLink = "https://seaofsea.com/public/api/verify_email.php?token=$newToken";
                $mailHandler->sendMail($email, 'Email Verification', "
                    <h1>Email Verification</h1>
                    <p>Please click the link below to verify your email:</p>
                    <a href=\"$verificationLink\">Verify Email</a>
                ");

                $message = "A new verification email has been sent to $email.";
                $messageType = "info";
                $logger->info("Verification email resent.", ['email' => $email]);
            }
        } catch (Exception $e) {
            $message = "An error occurred while sending the email.";
            $messageType = "error";
            $logger->error("Error resending verification email.", ['exception' => $e]);
        }
    } else {
        $message = "Invalid email format.";
        $messageType = "error";
        $logger->error("Invalid email format.", ['email' => $email]);
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