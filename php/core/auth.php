<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    private static $secret_key = 'secret_key_here'; // Güçlü bir key belirle
    private static $algorithm = 'HS256';

    // Token Oluşturma
    public static function createToken($user_id) {
        $payload = [
            'iss' => 'localhost',
            'iat' => time(),
            'exp' => time() + 3600, // Token geçerlilik süresi: 1 saat
            'user_id' => $user_id
        ];
        return JWT::encode($payload, self::$secret_key, self::$algorithm);
    }

    // Token Doğrulama
    public static function verifyToken($token) {
        try {
            return JWT::decode($token, new Key(self::$secret_key, self::$algorithm));
        } catch (Exception $e) {
            return null;
        }
    }
}
?>
