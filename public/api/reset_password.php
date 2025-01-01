<?php
require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/PasswordResetHandler.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$dbConnection = DatabaseHandler::getInstance()->getConnection();
$resetHandler = new PasswordResetHandler($dbConnection);

$token = $_GET['token'] ?? null;

$title = "Reset Password";
$message = "Invalid request.";
$messageType = "error";
$showForm = false;

if ($token) {
    $resetRequest = $resetHandler->verifyResetToken($token);

    if ($resetRequest) {
        if (strtotime($resetRequest['expires_at']) < time()) {
            $message = "The password reset link has expired.";
        } else {
            $showForm = true;
        }
    } else {
        $message = "Invalid or expired token.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $message = "Passwords do not match.";
        $messageType = "error";
        $showForm = true;
    } elseif (strlen($newPassword) < 6) {
        $message = "Password must be at least 6 characters.";
        $messageType = "error";
        $showForm = true;
    } else {
        $email = $resetRequest['email'];
        if ($resetHandler->resetPassword($email, $newPassword)) {
            $message = "Your password has been successfully reset.";
            $messageType = "success";

            // Şifre sıfırlama talebini sil
            $resetHandler->deleteResetRequest($email);
        } else {
            $message = "Failed to reset the password. Please try again.";
            $messageType = "error";
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
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f9f9f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
            width: 100%;
        }
        .message {
            font-size: 16px;
            margin-bottom: 20px;
        }
        .message.success {
            color: #28a745;
        }
        .message.error {
            color: #dc3545;
        }
        form {
            margin-top: 20px;
        }
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        button:hover {
            background: #0056b3;
        }
    </style>
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
