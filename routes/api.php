<?php
require_once 'controllers/UserController.php';
require_once 'middlewares/AuthMiddleware.php';

header("Content-Type: application/json");

$uri = explode('?', $_SERVER['REQUEST_URI'])[0];

$userController = new UserController();

switch ($uri) {
    case '/api/login':
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->login($data['email'], $data['password']);
        break;

    case '/api/protected':
        AuthMiddleware::validateToken();
        echo json_encode(["message" => "This is a protected route"]);
        break;

    default:
        http_response_code(404);
        echo json_encode(["message" => "Route not found"]);
        break;
}
