<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/handlers/PasswordResetHandler.php';

header('Content-Type: application/json');

$resetHandler = new PasswordResetHandler();
$data = json_decode(file_get_contents('php://input'), true);

$email = $data['email'] ?? '';
$newPassword = $data['new_password'] ?? '';
$confirmPassword = $data['confirm_password'] ?? '';

if ($newPassword !== $confirmPassword) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit;
}

try {
    if ($resetHandler->resetPassword($email, $newPassword)) {
        echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to reset the password.']);
    }
} catch (\Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
