<?php
require_once __DIR__ . '/init.php'; // Veritabanı bağlantısı
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/../../core/auth.php'; // JWT Auth sınıfı

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $user = new User($db);

    switch ($action) {
        // Kullanıcı Girişi
        case 'login':
            $username = $_POST['username'];
            $password = $_POST['password'];
            $result = $user->login($username, $password);

            if ($result) {
                $token = Auth::createToken($result['id']);
                echo json_encode(['status' => 'success', 'token' => $token]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
            }
            break;

        // Yeni Kullanıcı Ekle
        case 'create':
            $name = $_POST['name'];
            $email = $_POST['email'];
            $password = $_POST['password'];
            if ($user->createUser($name, $email, $password)) {
                echo json_encode(['status' => 'success', 'message' => 'User created successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'User creation failed']);
            }
            break;

        // Kullanıcı Bilgilerini Getir
        case 'read':
            $id = $_POST['id'] ?? null;
            $result = $user->getUser($id);
            echo json_encode(['status' => 'success', 'data' => $result]);
            break;

        // Kullanıcı Bilgilerini Güncelle
        case 'update':
            $id = $_POST['id'];
            $name = $_POST['name'];
            $email = $_POST['email'];
            if ($user->updateUser($id, $name, $email)) {
                echo json_encode(['status' => 'success', 'message' => 'User updated successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Update failed']);
            }
            break;

        // Kullanıcıyı Sil
        case 'delete':
            $id = $_POST['id'];
            if ($user->deleteUser($id)) {
                echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
            }
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
}
?>
