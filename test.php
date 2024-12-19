<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer Autoloader
require_once __DIR__ . '/config/database.php'; // Veritabanı bağlantısı
require_once __DIR__ . '/controller/UserController.php'; // Kullanıcı işlemleri
use Firebase\JWT\JWT;

use App\Utils\LoggerHelper;
use Dotenv\Dotenv;

// .env dosyasını yükle
$dotenv = Dotenv::createImmutable(__DIR__ . '/');
$dotenv->load();

session_start(); // Token'ı saklamak için oturum başlatılıyor
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanıcı İşlemleri Test Sayfası</title>
</head>
<body>
    <h1>Kullanıcı İşlemleri Test Sayfası</h1>

    <?php if (empty($_SESSION['token'])): ?>
        <h2>Giriş Yap</h2>
        <form method="POST">
            <label for="email">Email:</label>
            <input type="email" name="email" id="email" required>
            <br><br>
            <label for="password">Şifre:</label>
            <input type="password" name="password" id="password" required>
            <br><br>
            <button type="submit" name="operation" value="login">Giriş Yap</button>
        </form>
    <?php else: ?>
        <h2>Token:</h2>
        <pre><?php echo $_SESSION['token']; ?></pre>
        <form method="POST">
            <label for="operation">Bir işlem seçin:</label>
            <select name="operation" id="operation">
                <option value="register">Kullanıcı Kaydı</option>
                <option value="list_users">Kullanıcıları Listele</option>
                <option value="delete_user">Kullanıcı Sil</option>
            </select>
            <br><br>
            <label for="user_data">Kullanıcı Bilgileri (JSON formatında):</label><br>
            <textarea name="user_data" id="user_data" rows="5" cols="40">{ "name": "John Doe", "surname": "Doe", "email": "john@example.com", "password": "123456" }</textarea>
            <br><br>
            <button type="submit">Çalıştır</button>
        </form>
    <?php endif; ?>

    <?php if (!empty($response)): ?>
        <h2>Sonuç:</h2>
        <pre><?php echo print_r($response, true); ?></pre>
    <?php endif; ?>
</body>
</html>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operation = $_POST['operation'];
    $response = '';
    $userController = new \App\Controllers\UserController();

    switch ($operation) {
        case 'login':
            $email = $_POST['email'];
            $password = $_POST['password'];

            // Kullanıcıyı doğrula ve token oluştur
            $user = $userController->login($email, $password);
            if (!empty($user['error'])) {
                $response = $user['error'];
            } else {
                $payload = [
                    'id' => $user['id'],
                    'role' => $user['role'],
                    'iat' => time(),
                    'exp' => time() + 3600, // Token 1 saat geçerli
                ];
                $token = JWT::encode($payload, getenv('JWT_SECRET'), 'HS256');
                $_SESSION['token'] = $token;
                $response = 'Giriş başarılı! Token oluşturuldu.';
            }
            break;

        case 'register':
        case 'list_users':
        case 'delete_user':
            if (empty($_SESSION['token'])) {
                $response = 'Hata: Önce giriş yapmalısınız.';
            } else {
                $response = $userController->$operation($_POST['user_data'], $_SESSION['token']);
            }
            break;

        default:
            $response = 'Geçersiz işlem.';
            break;
    }
}
?>
