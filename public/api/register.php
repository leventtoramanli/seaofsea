<?php
require_once __DIR__ . '/../../lib/handlers/DatabaseHandler.php';
require_once __DIR__ . '/../../lib/handlers/UserHandler.php';

header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['register_attempts'])) {
    $_SESSION['register_attempts'] = 0;
    $_SESSION['last_register_time'] = time();
}

if ($_SESSION['register_attempts'] >= 5 && (time() - $_SESSION['last_register_time']) < 60) {
    die(json_encode(['success' => false, 'message' => 'Too many registration attempts. Please try again later.']));
}
/*
Reset tokenlarını otomatik olarak sıfırla
if (!isset($_SESSION['reset_attempts'])) {
    $_SESSION['reset_attempts'] = 0;
    $_SESSION['last_reset_time'] = time();
}

if ($_SESSION['reset_attempts'] >= 3 && (time() - $_SESSION['last_reset_time']) < 3600) {
    die(json_encode(['success' => false, 'message' => 'Too many password reset requests. Please try again later.']));
}

$_SESSION['reset_attempts']++;
$_SESSION['last_reset_time'] = time();
*/

$_SESSION['register_attempts']++;
$_SESSION['last_register_time'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHandler = new DatabaseHandler();
    $connection = $dbHandler->getConnection();

    $userHandler = new UserHandler($connection);

    // Gelen veriyi al
    $inputData = json_decode(file_get_contents('php://input'), true);

    // Kullanıcı kaydını işle
    $result = $userHandler->validateAndRegisterUser($inputData);

    // Sonucu döndür
    echo json_encode($result);

    $dbHandler->closeConnection();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
