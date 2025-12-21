<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';

class UserHandler
{
     public static function get_profile(array $params): array
    {
        $auth   = Auth::requireAuth();
        $userId = $auth['user_id'];
        $crud   = new Crud($userId);

        // Flutter tarafında kullanılan alanlar:
        $cols = ['id','name','surname','user_image','dob','pob','gender','maritalStatus'];
        $user = $crud->read('users', ['id' => $userId], $cols, false);

        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        return ['success' => true, 'data' => $user];
    }

     public static function getProfile(array $params): array
    {
        return self::get_profile($params);
    }

     public static function listCountries(array $params): array
    {
        $auth = Auth::requireAuth(); // erişim için gerekliyse
        $crud = new Crud($auth['user_id']);

        $rows = $crud->read(
            'cities',
            [],
            // MIN(id) benzeri agregasyon kullanıyorsan Crud sınıfın desteklemiyorsa basit alternatif:
            // tekil ülke listesi için DISTINCT:
            ['DISTINCT country AS name', 'MIN(id) AS id', 'iso2 AS code2', 'iso3 AS code'],
            true,
            // Eğer Crud bu tarz raw/select desteklemiyorsa, basitçe tüm satırları çekip PHP'de gruplayabilirsin.
        );

        return ['success' => true, 'data' => $rows];
    }

    public static function get_public_profile(array $params): array
    {
        $auth     = Auth::requireAuth();
        $viewerId = (int)$auth['user_id'];
        $targetId = (int)($params['user_id'] ?? 0);
        if ($targetId <= 0) {
            return ['success' => false, 'message' => 'user_id is required', 'data' => []];
        }

        $crud = new Crud($viewerId);
        // Public göstermek istediğimiz alanlar:
        $cols = ['id','name','surname','user_image','dob','pob','gender','maritalStatus'];
        $u = $crud->read('users', ['id' => $targetId], $cols, false);
        if (!$u) {
            return ['success' => false, 'message' => 'User not found', 'data' => []];
        }

        // İsteğe bağlı tek satırlık full_name
        $u['full_name'] = trim(($u['name'] ?? '').' '.($u['surname'] ?? ''));

        // İleride privacy kuralı eklemek istersen:
        // if ($viewerId !== $targetId) { ... alan maskeleme ... }

        return ['success' => true, 'data' => $u];
    }

    // Alias (opsiyonel): user.getPublicProfile
    public static function getPublicProfile(array $params): array
    {
        return self::get_public_profile($params);
    }

    public static function listCitiesByCountry(array $params): array
    {
        $country = $params['country'] ?? '';
        if (!$country) {
            return ['success' => false, 'message' => 'Country name is required', 'data' => []];
        }

        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);

        $rows = $crud->read(
            'cities',
            ['country' => $country, 'capital' => ['IN', ['admin','primary']]],
            ['id', 'city AS name'],
            true
        );

        return ['success' => true, 'data' => $rows];
    }

    public static function blockUser(array $params): void
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);

        // Yetki kontrolü
        Gate::check('user.block');

        $targetUserId = $params['target_user_id'] ?? null;
        $blockedUntil = $params['blocked_until'] ?? null;

        if (!$targetUserId || !$blockedUntil) {
            Response::error("target_user_id and blocked_until are required.", 400);
        }

        // Kendi kendini bloklama engeli
        if ((int)$targetUserId === (int)$auth['user_id']) {
            Response::error("You cannot block your own account.", 400);
        }

        // Kullanıcıyı kontrol et
        $targetUser = $crud->read('users', ['id' => $targetUserId], false);
        if (!$targetUser) {
            Response::error("Target user not found.", 404);
        }

        // Tarih formatını doğrula
        if (strtotime($blockedUntil) === false) {
            Response::error("Invalid date format for blocked_until.", 400);
        }

        // Güncelle
        $update = $crud->update('users', ['blocked_until' => $blockedUntil], ['id' => $targetUserId]);

        if ($update) {
            Response::success([
                'message' => "User ID {$targetUserId} blocked until {$blockedUntil}."
            ]);
        } else {
            Response::error("Failed to update blocked_until.", 500);
        }
    }
}
