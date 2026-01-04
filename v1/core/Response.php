<?php

class Response
{
    private static function jsonOut(array $payload, int $code): void
    {
        if (!headers_sent())
            http_response_code($code);
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
            'data' => self::normalizeDates($data),
            'code' => $code
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
            'data' => self::normalizeDates($data),
            'code' => $code
        ];
    }

    public static function ok(array $data = [], string $message = 'Success', int $code = 200): void
    {
        self::jsonOut(['success' => true, 'message' => $message, 'data' => $data, 'code' => $code], $code);
        exit;
    }
    public static function fails(int $code, string $message, array $data = []): void
    {
        self::jsonOut(['success' => false, 'message' => $message, 'error' => self::errKey($code), 'code' => $code, 'data' => $data], $code);
        exit;
    }

    private static function errKey(int $code): string
    {
        return match ($code) {
            400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 422 => 'validation_error',
            default => 'internal_error'
        };
    }

    // Response.php içine ekle

    private static function normalizeDates(mixed $data): mixed
    {
        if (!is_array($data))
            return $data;

        $out = [];
        foreach ($data as $k => $v) {
            // recursive
            if (is_array($v)) {
                $out[$k] = self::normalizeDates($v);
                continue;
            }

            // sadece string + datetime anahtarlarına dokun
            if (is_string($k) && is_string($v) && self::isDateKey($k)) {
                $out[$k] = self::toIsoZulu($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function isDateKey(string $key): bool
    {
        // created_at, updated_at, changed_at, submitted_at, read_at...
        // blocked_until gibi alanları da kapsasın diye:
        return (bool) preg_match('/(_at|_until)$/', $key);
    }

    private static function toIsoZulu(string $value): string
    {
        $s = trim($value);
        if ($s === '')
            return $value;

        // 1) "YYYY-MM-DD HH:MM:SS" -> UTC kabul et ve ISO-Z yap
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $s)) {
            try {
                $dt = new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
                return $dt->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
                return $value;
            }
        }

        // 2) ISO gibi (T içeriyorsa) -> UTC ISO-Z normalize etmeyi dene
        if (strpos($s, 'T') !== false) {
            try {
                $dt = new \DateTimeImmutable($s);
                $dtUtc = $dt->setTimezone(new \DateTimeZone('UTC'));
                return $dtUtc->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable $e) {
                return $value;
            }
        }

        return $value;
    }
}
