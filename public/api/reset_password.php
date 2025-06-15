<?php
ini_set('display_errors', 'On');
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/handlers/PasswordResetHandler.php';

$resetHandler = new PasswordResetHandler();

$token = $_GET['token'] ?? null;
$showForm = false;
$message = "Invalid request.";

if ($token) {
    try {
        $resetRequest = $resetHandler->verifyResetToken($token);
        $showForm = true;
        $email = $resetRequest->email;
        $message = "Please enter your new password.";
    } catch (\Exception $e) {
        $message = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    if ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match.";
    } elseif (strlen($newPassword) < 6) {
        $message = "Password must be at least 6 characters.";
    } else {
        try {
            if ($resetHandler->resetPassword($email, $newPassword)) {
                $message = "Your password has been successfully reset.";
                $showForm = false;
            } else {
                $message = "Failed to reset the password.";
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset</title>
    <link rel="stylesheet" href="../../lib/css/mail.css">
</head>
<body>
    <div class="container">
        <h1>Password Reset</h1>
        <p class="message <?= htmlspecialchars($showForm ? 'info' : (stripos($message, 'success') !== false ? 'success' : 'error')) ?>">
            <?= htmlspecialchars($message) ?>
        </p>

        <?php if ($showForm): ?>
            <p class="email-display">Resetting password for: <strong><?= htmlspecialchars($email) ?></strong></p>
            <form id="passwordResetForm" method="POST">
                <div class="input-group">
                    <input type="password" id="new_password" name="new_password" placeholder="New Password" required>
                    <small class="error-message"></small>
                </div>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                    <small class="error-message"></small>
                </div>
                <button type="submit">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>
    <script src="../../lib/js/passwordReset.js"></script>
</body>
</html>
