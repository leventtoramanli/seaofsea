<?php
require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';
require_once __DIR__ . '/../../lib/handlers/MailHandler.php';

$dbHandler = new DatabaseHandler();
$crud = new CRUDHandler($dbHandler->getConnection());
$mailHandler = new MailHandler();

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
        } else {
            $updated = $crud->update('users', ['is_verified' => 1, 'role_id' => 2], ['id' => $verification['user_id']]);
            if ($updated) {
                $crud->delete('verification_tokens', ['id' => $verification['id']]);
                $message = "Your email has been successfully verified!";
                $messageType = "success";
            } else {
                $message = "Failed to verify email. Please try again.";
            }
        }
    } else {
        $message = "Invalid verification token.";
        $showResendButton = true; // Geçersiz token için butonu göster
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_email'])) {
    $email = $_POST['email'];

    $user = $crud->read('users', ['email' => $email]);

    if (!$user) {
        $message = "No user found with the provided email.";
        $messageType = "error";
    } else {
        $newToken = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $crud->delete('verification_tokens', ['user_id' => $user['id']]); // Eski tokeni sil
        $crud->create('verification_tokens', [
            'user_id' => $user['id'],
            'token' => $newToken,
            'expires_at' => $expiresAt
        ]);

        $verificationLink = "http://seaofsea.com/api/verify_email.php?token=$newToken";
        $mailHandler->sendMail($email, 'Email Verification', "
            <h1>Email Verification</h1>
            <p>Please click the link below to verify your email:</p>
            <a href=\"$verificationLink\">Verify Email</a>
        ");

        $message = "A new verification email has been sent to $email.";
        $messageType = "info";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <style>
        /* Görsel düzenlemeler */
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
        .message.info {
            color: #007bff;
        }
        form {
            margin-top: 20px;
        }
        input[type="email"] {
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
        <h1><?= $title ?></h1>
        <p class="message <?= $messageType ?>"><?= $message ?></p>

        <?php if ($showResendButton): ?>
            <form method="POST">
                <input type="email" name="email" placeholder="Enter your email" required>
                <button type="submit" name="resend_email">Resend Verification Email</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
