<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/handlers/PasswordResetHandler.php';

$resetHandler = new PasswordResetHandler();

$token = $_GET['token'] ?? null;
$showForm = false;
$message = "Invalid request.";

if ($token) {
    try {
        $resetRequest = $resetHandler->verifyResetToken($token);
        $showForm = true;
        $email = $resetRequest->email;
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
</head>
<body>
    <h1>Password Reset</h1>
    <p><?= htmlspecialchars($message) ?></p>

    <?php if ($showForm): ?>
        <form method="POST">
            <input type="password" name="new_password" placeholder="New Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit">Reset Password</button>
        </form>
    <?php endif; ?>
</body>
</html>
