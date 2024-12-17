<?php
require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../plugins/users/userRoutes.php';

header('Content-Type: application/json');

// Authorization kontrolü
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    $token = str_replace('Bearer ', '', $authHeader);
    $decoded = Auth::verifyToken($token);

    if ($decoded) {
        echo json_encode(['status' => 'success', 'user_id' => $decoded->user_id]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} else {
    echo json_encode(['error' => 'Authorization header missing']);
}
?>
