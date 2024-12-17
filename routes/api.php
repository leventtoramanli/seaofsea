<?php
require_once 'controllers/UserController.php';
require_once 'middlewares/AuthMiddleware.php';

$userController = new UserController();
$uri = explode('?', $_SERVER['REQUEST_URI'])[0];

switch ($uri) {
    case '/api/register':
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->register($data['name'], $data['surname'], $data['email'], $data['password']);
        break;

    case '/api/login':
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->login($data['email'], $data['password']);
        break;

    case '/api/users':
        AuthMiddleware::validateToken();
        $userController->getUsers();
        break;

    case '/api/users/update':
        AuthMiddleware::validateToken();
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->updateUser($data['id'], $data['name'], $data['email']);
        break;

    case '/api/users/delete':
        AuthMiddleware::validateToken();
        $data = json_decode(file_get_contents("php://input"), true);
        $userController->deleteUser($data['id']);
        break;

    default:
        http_response_code(404);
        echo json_encode(["message" => "Route not found"]);
        break;
}
