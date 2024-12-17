<?php
class CorsMiddleware {
    public static function handle() {
        // CORS başlıklarını ayarlıyoruz
        header("Access-Control-Allow-Origin: *"); // Tüm domain'lere izin verir
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");

        // Preflight (OPTIONS) isteklerini kontrol et
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}
?>