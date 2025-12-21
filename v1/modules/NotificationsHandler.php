<?php
// v1/modules/NotificationsHandler.php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/logla.php';

class NotificationsHandler
{
    private const T_NOTIFICATIONS = 'notifications';

    private static function ok($data, string $message = 'OK', int $code = 200): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ];
    }

    private static function fail(string $message, int $code = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'code' => $code,
        ];
    }

    private static function getPage(array $params): int
    {
        $page = (int) ($params['page'] ?? 1);
        return $page > 0 ? $page : 1;
    }

    private static function getPerPage(array $params, int $default = 20, int $max = 100): int
    {
        $per = (int) ($params['per_page'] ?? $default);
        if ($per <= 0)
            $per = $default;
        if ($per > $max)
            $per = $max;
        return $per;
    }

    /*
     * Listeleme – kullanıcının kendi bildirimleri
     */
    public static function list(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $page = self::getPage($params);
            $perPage = self::getPerPage($params, 20, 100);
            $offset = ($page - 1) * $perPage;

            $onlyUnread = !empty($params['only_unread']);

            $crud = new Crud($userId);

            $conditions = [
                'user_id' => $userId,
            ];
            if ($onlyUnread) {
                $conditions['is_read'] = 0;
            }

            // Toplam
            $total = $crud->count(self::T_NOTIFICATIONS, $conditions);
            if ($total === false) {
                return self::fail('Could not count notifications', 500);
            }

            // Liste
            $items = $crud->read(
                self::T_NOTIFICATIONS,
                $conditions,
                ['id', 'type', 'title', 'body', 'meta_json', 'is_read', 'created_at'],
                true,
                ['id' => 'DESC'],
                [],
                ['limit' => $perPage, 'offset' => $offset]
            );

            if ($items === false) {
                return self::fail('Could not load notifications', 500);
            }

            if (is_array($items)) {
                foreach ($items as &$it) {
                    $meta = null;
                    if (!empty($it['meta_json'])) {
                        $decoded = json_decode($it['meta_json'], true);
                        if (is_array($decoded)) {
                            $meta = $decoded;
                        }
                    }
                    $it['meta'] = $meta;
                }
                unset($it);
            }

            return self::ok([
                'items' => $items,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ], 'Notifications list');
        } catch (Throwable $e) {
            Logger::exception($e, 'Notifications.list failed');
            return self::fail('Internal server error', 500);
        }
    }

    /*
     * Tek bildirimi read işaretleme
     */
    public static function mark_read(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $id = (int) ($params['id'] ?? 0);
            if ($id <= 0) {
                return self::fail('id is required', 422);
            }

            $crud = new Crud($userId);

            $ok = $crud->update(
                self::T_NOTIFICATIONS,
                ['is_read' => 1],
                ['id' => $id, 'user_id' => $userId]
            );

            if (!$ok) {
                return self::fail('Notification not found or not updated', 404);
            }

            return self::ok(['id' => $id], 'Notification marked as read');
        } catch (Throwable $e) {
            Logger::exception($e, 'Notifications.mark_read failed');
            return self::fail('Internal server error', 500);
        }
    }

    /*
     * Tüm bildirimleri read işaretleme
     */
    public static function mark_all_read(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $crud = new Crud($userId);

            $crud->update(
                self::T_NOTIFICATIONS,
                ['is_read' => 1],
                ['user_id' => $userId]
            );

            return self::ok(['updated' => true], 'All notifications marked as read');
        } catch (Throwable $e) {
            Logger::exception($e, 'Notifications.mark_all_read failed');
            return self::fail('Internal server error', 500);
        }
    }

    /*
     * Okunmamış sayısı
     */
    public static function unread_count(array $params): array
    {
        try {
            $auth = Auth::requireAuth();
            $userId = (int) $auth['user_id'];

            $crud = new Crud($userId);

            $cnt = $crud->count(self::T_NOTIFICATIONS, [
                'user_id' => $userId,
                'is_read' => 0,
            ]);

            if ($cnt === false) {
                return self::fail('Could not count unread notifications', 500);
            }

            return self::ok(['unread_count' => (int) $cnt], 'Unread notifications count');
        } catch (Throwable $e) {
            Logger::exception($e, 'Notifications.unread_count failed');
            return self::fail('Internal server error', 500);
        }
    }
}
