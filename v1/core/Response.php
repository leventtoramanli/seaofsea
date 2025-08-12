<?php

class Response
{
    public static function success($data = null, string $message = 'Success', int $code = 200): void
    {
        http_response_code($code);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    public static function error(string $message = 'An error occurred', int $code = 400): void
    {
        Logger::getInstance()->error($message);
        http_response_code($code);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }

    public static function fail(string $message = 'Error', int $code = 400, $data = []): array
    {
        // HTTP durum kodunu da ayarlamak istersen:
        if (!headers_sent()) {
            http_response_code($code);
        }

        return [
            'success' => false,
            'message' => $message,
            'data'    => $data,
        ];
    }
}
