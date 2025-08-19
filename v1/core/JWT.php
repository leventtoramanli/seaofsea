<?php

class JWT
{
    private static function base64UrlEncode($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = 4 - (strlen($data) % 4);
        if ($pad < 4) $data .= str_repeat('=', $pad);
        return base64_decode($data);
    }

    public static function encode(array $payload, string $secret, int $expiration = 3600): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        //$payload['exp'] = time() + $expiration;
        $now = time();
        $payload['iat'] = $payload['iat'] ?? $now;
        $payload['nbf'] = $payload['nbf'] ?? ($now - 1);
        $payload['exp'] = $payload['exp'] ?? ($now + $expiration);

        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret, int $leeway = 30): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            Logger::getInstance()->error('JWT decode: bad token parts');
            return null;
        }

        [$h, $p, $s] = $parts;
        $headerJson  = self::base64UrlDecode($h);
        $payloadJson = self::base64UrlDecode($p);
        $header  = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        $sig     = self::base64UrlDecode($s);

        if (!is_array($header) || !is_array($payload) || $sig === '' || $sig === false) {
            Logger::getInstance()->error('JWT decode: json/signature parse failed');
            return null;
        }

        $calc = hash_hmac('sha256', "$h.$p", $secret, true);
        if (!hash_equals($calc, $sig)) {
            Logger::getInstance()->error('JWT decode: signature mismatch');
            return null;
        }

        $now = time();
        $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
        $nbf = isset($payload['nbf']) ? (int)$payload['nbf'] : 0;
        $iat = isset($payload['iat']) ? (int)$payload['iat'] : 0;

        if ($nbf && $now + $leeway < $nbf) {
            Logger::getInstance()->info("JWT not yet valid: now=$now nbf=$nbf");
            return null;
        }
        if ($iat && $iat - $leeway > $now) {
            Logger::getInstance()->info("JWT issued in future: now=$now iat=$iat");
            return null;
        }
        if ($exp && $now - $leeway > $exp) {
            Logger::getInstance()->info("JWT expired: now=$now exp=$exp diff=" . ($now - $exp));
            return null;
        }

        return $payload;
    }
}
