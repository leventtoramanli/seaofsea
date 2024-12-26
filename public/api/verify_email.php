<?php
require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/CRUDHandlers.php';

$dbHandler = new DatabaseHandler();
$crud = new CRUDHandler($dbHandler->getConnection());

// Tokeni al
$token = $_GET['token'] ?? null;

// Görsel mesajlar
$title = "Email Verification";
$message = "Invalid request.";
$messageType = "error";

if ($token) {
    // Tokeni veritabanında kontrol et
    $verification = $crud->read('verification_tokens', ['token' => $token]);
    if ($verification) {
        // Süre dolmuş mu kontrol et
        if (strtotime($verification[0]['expires_at']) < time()) {
            $message = "The verification link has expired.";
        } else {
            // Kullanıcıyı doğrula
            $updated = $crud->update('users', ['is_verified' => 1], ['id' => $verification[0]['user_id']]);
            
            if ($updated) {
                // Tokeni sil
                $crud->delete('verification_tokens', ['id' => $verification[0]['id']]);
                $message = "Your email has been successfully verified!";
                $messageType = "success";
            } else {
                $message = "Failed to verify email. Please try again.";
            }
        }
    } else {
        $message = "Invalid or already used verification token.";
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
        body {
            font-family: Arial, sans-serif;
            background: #f9f9f9;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #333;
        }
        .container {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            max-width: 400px;
            width: 100%;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #444;
        }
        p {
            font-size: 16px;
            line-height: 1.5;
        }
        .message.success {
            color: #28a745;
        }
        .message.error {
            color: #dc3545;
        }
        .btn {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            font-size: 14px;
            text-decoration: none;
            color: white;
            border-radius: 5px;
            background: #007bff;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= $title ?></h1>
        <p class="message <?= $messageType ?>"><?= $message ?></p>
        <?php if ($messageType === "success"): ?>
            <a href="/login.php" class="btn">Go to Login</a>
        <?php endif; ?>
    </div>
</body>
</html>
