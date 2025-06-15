<?php

class JWT
{
    private static function base64UrlEncode($data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function encode(array $payload, string $secret, int $expiration = 3600): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload['exp'] = time() + $expiration;

        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        $signature = self::base64UrlDecode($encodedSignature);

        if (!$header || !$payload || !$signature) {
            return null;
        }

        // Check signature
        $validSignature = hash_hmac('sha256', "$encodedHeader.$encodedPayload", $secret, true);
        if (!hash_equals($validSignature, $signature)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && time() > $payload['exp']) {
            return null;
        }

        return $payload;
    }
}
