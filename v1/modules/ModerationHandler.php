<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Gate.php';
require_once __DIR__ . '/../core/Crud.php';

class ModerationHandler
{
    private static Crud $crud;

    private static function init(int $userId): void
    {
        self::$crud = new Crud($userId);
    }

    /**
     * module=moderation, action=blockUser
     * params: target_user_id (int, required)
     *         until (Y-m-d H:i:s, optional)  |  duration_hours (int, optional)
     *         reason (string, optional)
     * Not: Admin kendi hesabını bloklayamaz.
     */
    public static function blockUser(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init($auth['user_id']);

        // Admin yetkisi gerekli (email + block kontrolü Gate::check içinde var)
        Gate::check('admin.access');

        $targetId = (int)($params['target_user_id'] ?? 0);
        if ($targetId <= 0) {
            return ['success' => false, 'message' => 'target_user_id is required'];
        }
        if ($targetId === (int)$auth['user_id']) {
            return ['success' => false, 'message' => "You cannot block yourself"];
        }

        $user = self::$crud->read('users', ['id' => $targetId], false);
        if (!$user) {
            return ['success' => false, 'message' => 'Target user not found'];
        }

        // süre hesapla
        $until = null;
        if (!empty($params['until'])) {
            $ts = strtotime((string)$params['until']);
            if ($ts === false) {
                return ['success' => false, 'message' => "Invalid 'until' value. Use 'Y-m-d H:i:s'"];
            }
            $until = date('Y-m-d H:i:s', $ts);
        } elseif (!empty($params['duration_hours'])) {
            $h = (int)$params['duration_hours'];
            if ($h <= 0) {
                return ['success' => false, 'message' => 'duration_hours must be positive'];
            }
            $until = date('Y-m-d H:i:s', time() + $h * 3600);
        } else {
            // varsayılan 24 saat
            $until = date('Y-m-d H:i:s', time() + 24 * 3600);
        }

        $reason = isset($params['reason']) ? trim((string)$params['reason']) : null;

        $ok = self::$crud->update('users', [
            'blocked_until' => $until,
            'block_reason'  => $reason,
        ], ['id' => $targetId]);

        if (!$ok) {
            return ['success' => false, 'message' => 'Failed to block user'];
        }

        return [
            'success' => true,
            'user_id' => $targetId,
            'blocked_until' => $until,
            'block_reason'  => $reason,
        ];
    }

    /**
     * module=moderation, action=unblockUser
     * params: target_user_id (int, required)
     */
    public static function unblockUser(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init($auth['user_id']);

        Gate::check('admin.access');

        $targetId = (int)($params['target_user_id'] ?? 0);
        if ($targetId <= 0) {
            return ['success' => false, 'message' => 'target_user_id is required'];
        }
        if ($targetId === (int)$auth['user_id']) {
            return ['success' => false, 'message' => "You cannot unblock yourself here"];
        }

        $user = self::$crud->read('users', ['id' => $targetId], false);
        if (!$user) {
            return ['success' => false, 'message' => 'Target user not found'];
        }

        $ok = self::$crud->update('users', [
            'blocked_until' => null,
            'block_reason'  => null,
        ], ['id' => $targetId]);

        if (!$ok) {
            return ['success' => false, 'message' => 'Failed to unblock user'];
        }

        return ['success' => true, 'user_id' => $targetId];
    }

    /**
     * module=moderation, action=getBlockStatus
     * params: target_user_id (int, required)
     * Not: Kendisi ya da admin görebilir.
     */
    public static function getBlockStatus(array $params): array
    {
        $auth = Auth::requireAuth();
        self::init($auth['user_id']);

        $targetId = (int)($params['target_user_id'] ?? 0);
        if ($targetId <= 0) {
            return ['success' => false, 'message' => 'target_user_id is required'];
        }

        if ($targetId !== (int)$auth['user_id']) {
            // sadece admin başkasının durumunu görebilsin
            Gate::checkPermissionOnly('admin.access');
        }

        $user = self::$crud->read('users', ['id' => $targetId], false);
        if (!$user) {
            return ['success' => false, 'message' => 'Target user not found'];
        }

        return [
            'success'       => true,
            'user_id'       => $targetId,
            'blocked_until' => $user['blocked_until'] ?? null,
            'block_reason'  => $user['block_reason'] ?? null,
        ];
    }
}
