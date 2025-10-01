<?php

class Response
{
    private static function jsonOut(array $payload, int $code): void {
        if (!headers_sent()) http_response_code($code);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
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
            'code' => $code,
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

    public static function ok(array $data = [], string $message='Success', int $code=200): void {
        self::jsonOut(['success'=>true,'message'=>$message,'data'=>$data,'code'=>$code], $code);
        exit;
    }
    public static function fails(int $code, string $message, array $data=[]): void {
        self::jsonOut(['success'=>false,'message'=>$message,'error'=>self::errKey($code),'code'=>$code,'data'=>$data], $code);
        exit;
    }

    private static function errKey(int $code): string {
        return match ($code) {
          400=>'bad_request',401=>'unauthorized',403=>'forbidden',404=>'not_found',409=>'conflict',422=>'validation_error',
          default=>'internal_error'
        };
    }
}
