<?php

class AuthService
{
    public static function can(?int $userId, string $action, string $table): bool
    {
        // Kullanıcı yoksa izin verilemez
        if (!$userId) return false;

        // 👇 İzin kontrolünü burada genişletebilirsin
        // Örnek: Tüm kullanıcılar şimdilik her tabloya erişebilsin
        return true;

        // Daha sonra şu şekilde geliştirilebilir:
        // 1. Kullanıcının rolünü kontrol et
        // 2. role_permissions tablosuna bak
        // 3. user_permissions tablosuna bak
        // 4. Özel kısıtları işle
    }
}
