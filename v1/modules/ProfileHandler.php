<?php

class ProfileHandler
{
    public static function getProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud($auth['user_id']);

        $users = $crud->read('users', ['id' => $auth['user_id']], [
            'id', 'name', 'surname', 'email', 'role_id',
            'user_image', 'cover_image', 'bio',
            'email_notifications', 'app_notifications', 'weekly_summary'
        ]);

        if (empty($users)) {
            Response::error("User not found.", 404);
        }

        return $users[0];
    }

    public static function updateProfile(array $params): array
    {
        $auth = Auth::requireAuth();
        $userId = $auth['user_id'];
        $crud = new Crud($userId);

        $fieldTypes = [
            'name' => 'string',
            'surname' => 'string',
            'dob' => 'date',
            'pob' => 'nullable_string',
            'gender' => 'string',
            'maritalStatus' => 'string',
            'bio' => 'nullable_string',
            'email_notifications' => 'bool',
            'app_notifications' => 'bool',
            'weekly_summary' => 'bool',
        ];

        $data = [];

        // Email özel kontrol
        if (!empty($params['email'])) {
            $existing = $crud->read('users', ['email' => $params['email']], ['id']);
            if ($existing && $existing[0]['id'] != $userId) {
                return ['success' => false, 'message' => 'This email is already in use.'];
            }
            $data['email'] = $params['email'];
            $data['is_verified'] = 0;
        }

        // Şifre özel kontrol
        if (!empty($params['password'])) {
            $data['password'] = password_hash($params['password'], PASSWORD_BCRYPT);
        }

        // Diğer alanları normalize ederek işleyelim
        foreach ($fieldTypes as $key => $type) {
            if (isset($params[$key])) {
                $data[$key] = self::normalizeValue($params[$key], $type);
            }
        }

        return $crud->update('users', ['id' => $userId], $data);
    }

    private static function normalizeValue($value, string $type)
    {
        $handlers = [
            'int' => fn($v) => (int) $v,
            'string' => fn($v) => trim((string)$v),
            'bool' => fn($v) => $v === true || $v === '1' || $v === 1 ? 1 : 0,
            'json' => fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE),
            'date' => fn($v) => date('Y-m-d', strtotime($v)),
            'nullable_string' => fn($v) => $v !== '' ? trim($v) : null,
            'float' => fn($v) => (float) $v,
        ];

        return $handlers[$type]($value) ?? $value;
    }
}