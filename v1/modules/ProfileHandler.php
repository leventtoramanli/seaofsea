<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/log.php';

class ProfileHandler
{
    private static Crud $crud;

    private static function init(int $userId): void
    {
        self::$crud = new Crud($userId);
    }

    /**
     * GET / profile.getProfile
     * - id verilmezse: kendi profili
     * - id verilirse: başka profil; görmek için en az 'profile.view' gerekir
     * Dönen veri: doğrudan kullanıcı objesi (Router bunu data içine sarar)
     */
    public static function getProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init((int)$auth['user_id']);

        $targetId = !empty($params['id']) ? (int)$params['id'] : (int)$auth['user_id'];

        // hedef kullanıcı
        $user = self::$crud->read('users', ['id' => $targetId], false);
        if (!$user) {
            return ['message' => 'User not found'];
        }

        // başkasının profili → profile.view gerekir
        if ($targetId !== (int)$auth['user_id']) {
            if (!PermissionService::hasPermission((int)$auth['user_id'], 'profile.view')) {
                return ['message' => 'Not authorized to view this profile'];
            }
        }

        // Sadece güvenli alanlar
        $allowed = [
            'id','name','surname','email','role_id','dob','pob','gender','maritalStatus',
            'user_image','cover_image','bio',
            'email_notifications','app_notifications','weekly_summary','is_verified'
        ];
        $filtered = array_intersect_key($user, array_flip($allowed));

        // Frontend flag’leri
        $filtered['isOwner']        = ($targetId === (int)$auth['user_id']);
        $filtered['emailVerified']  = (int)($user['is_verified'] ?? 0) === 1;

        return $filtered;
    }

    /**
     * POST / profile.updateProfile
     * - Sadece kullanıcı kendi profilini güncelleyebilir
     * - 'user.update_own' ve email verified = 1 şart
     */
    public static function updateProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        $me = (int)$auth['user_id'];
        self::init($me);

        $targetId = isset($params['user_id']) ? (int)$params['user_id'] : $me;

        // sadece kendi profili
        if ($targetId !== $me) {
            return ['message' => 'You cannot update another user\'s profile.'];
        }

        // mevcut kullanıcıyı çek
        $meRow = self::$crud->read('users', ['id' => $me], false);
        if (!$meRow) {
            return ['message' => 'User not found'];
        }

        // email verified şart
        if ((int)($meRow['is_verified'] ?? 0) === 0) {
            return ['message' => 'Please verify your email before updating profile.'];
        }

        // izin şart
        if (!PermissionService::hasPermission($me, 'user.update_own')) {
            return ['message' => 'Not allowed to update your profile.'];
        }

        // alanlar
        $fieldTypes = [
            'name'               => 'string',
            'surname'            => 'string',
            'dob'                => 'date',
            'pob'                => 'nullable_string',
            'gender'             => 'string',
            'maritalStatus'      => 'string',
            'bio'                => 'nullable_string',
            'email_notifications'=> 'bool',
            'app_notifications'  => 'bool',
            'weekly_summary'     => 'bool',
        ];

        $data = [];

        // email değişimi
        if (!empty($params['email'])) {
            $newEmail = trim((string)$params['email']);
            if ($newEmail !== ($meRow['email'] ?? '')) {
                $exists = self::$crud->read('users', ['email' => $newEmail], false);
                if ($exists && (int)$exists['id'] !== $targetId) {
                    return ['message' => 'This email is already in use.'];
                }
                $data['email'] = $newEmail;
                $data['is_verified'] = 0; // mail değiştiyse tekrar doğrulama
            }
        }

        // şifre değişimi (opsiyonel)
        if (!empty($params['password'])) {
            $data['password'] = password_hash($params['password'], PASSWORD_BCRYPT);
        }

        // diğer alanlar
        foreach ($fieldTypes as $k => $type) {
            if (array_key_exists($k, $params)) {
                $data[$k] = self::normalizeValue($params[$k], $type);
            }
        }

        if (empty($data)) {
            return ['message' => 'Nothing to update.'];
        }

        $ok = self::$crud->update('users', $data, ['id' => $targetId]);
        if (!$ok) {
            return ['message' => 'Profile update failed'];
        }

        return ['updated' => true];
    }

    private static function normalizeValue($value, string $type)
    {
        switch ($type) {
            case 'int':              return (int)$value;
            case 'bool':             return ($value === true || $value === '1' || $value === 1) ? 1 : 0;
            case 'json':             return json_encode($value, JSON_UNESCAPED_UNICODE);
            case 'date':             return date('Y-m-d', strtotime((string)$value));
            case 'nullable_string':  $t = trim((string)$value); return $t !== '' ? $t : null;
            case 'string':
            default:                 return trim((string)$value);
        }
    }
}
