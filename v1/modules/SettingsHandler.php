<?php

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/permissionGate.php'; // Gate

class SettingsHandler
{
    /**
     * Güvenli bool -> int çevirici
     * true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "on"/"off"
     */
    private static function parseBoolInt($v): ?int
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v ? 1 : 0;
        if (is_numeric($v)) return ((int)$v) ? 1 : 0;
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['1','true','yes','on'], true))  return 1;
            if (in_array($s, ['0','false','no','off'], true)) return 0;
        }
        return null;
    }

    /**
     * settings.get_notification_settings
     * Dönüş: success + üç tercih alanı (int 0/1)
     */
    public static function get_notification_settings(array $params): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $me = $crud->read('users', ['id' => (int)$auth['user_id']], false);
        if (!$me) {
            return ['success' => false, 'message' => 'User not found'];
        }

        // Block kontrolü (Gate ile uyumlu)
        if (!empty($me['blocked_until']) && strtotime((string)$me['blocked_until']) > time()) {
            return ['success' => false, 'message' => "Your account is blocked until {$me['blocked_until']}"];
        }

        return [
            'success'             => true,
            'email_notifications' => (int)($me['email_notifications'] ?? 0),
            'app_notifications'   => (int)($me['app_notifications'] ?? 0),
            'weekly_summary'      => (int)($me['weekly_summary'] ?? 0),
        ];
    }

    /**
     * settings.save_notification_settings
     * Body: email_notifications, app_notifications, weekly_summary (bool/int/string kabul)
     * Dönüş: success + güncel değerler (UI hemen senkron kalır)
     */
    public static function save_notification_settings(array $params): array
    {
        $auth = Auth::requireAuth();
        // Kendi ayarını değiştirmek için email doğrulaması gerekli
        Gate::checkVerified();

        $crud = new Crud((int)$auth['user_id']);

        $updates = [
            'email_notifications' => self::parseBoolInt($params['email_notifications'] ?? null),
            'app_notifications'   => self::parseBoolInt($params['app_notifications'] ?? null),
            'weekly_summary'      => self::parseBoolInt($params['weekly_summary'] ?? null),
        ];

        // null olanları gönderme (sadece gelen alanlar güncellensin)
        $updates = array_filter($updates, static fn($v) => $v !== null);

        if (!$updates) {
            return ['success' => false, 'message' => 'No fields to update.'];
        }

        $ok = $crud->update('users', $updates, ['id' => (int)$auth['user_id']]);
        if (!$ok) {
            return ['success' => false, 'message' => 'Save failed'];
        }

        // Güncel değerleri dön (Flutter direkt ekrana koyabilsin)
        $me = $crud->read('users', ['id' => (int)$auth['user_id']], false);

        return [
            'success'             => true,
            'email_notifications' => (int)($me['email_notifications'] ?? 0),
            'app_notifications'   => (int)($me['app_notifications'] ?? 0),
            'weekly_summary'      => (int)($me['weekly_summary'] ?? 0),
        ];
    }
}
