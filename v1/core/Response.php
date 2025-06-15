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
        http_response_code($code);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
}
