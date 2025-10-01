<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';

class ShipHandler
{
    /** GET: ship types list */
    public static function get_ship_types(array $params = []): array
    {
        // Auth zorunlu
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        // Basit filtre / sayfalama (isteğe bağlı)
        $q       = isset($params['q']) ? trim((string)$params['q']) : '';
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(1000, max(1, (int)($params['per_page'] ?? 200)));
        $offset  = ($page - 1) * $perPage;

        // Basit where + bind
        $where = [];
        $bind  = [];
        if ($q !== '') {
            $where[]   = '(name LIKE :q OR description LIKE :q)';
            $bind[':q'] = '%'.$q.'%';
        }
        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        // Not: Crud::query projendeki generic raw query fonksiyonu; yoksa read ile de çözülebilir
        $sql  = "SELECT id, name, description FROM ship_types $whereSql ORDER BY name ASC LIMIT :lim OFFSET :off";
        $bind[':lim'] = $perPage;
        $bind[':off'] = $offset;

        $rows = $crud->query($sql, $bind) ?: [];

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => $rows,
            'code'    => 200,
        ];
    }

    /** Geri uyumluluk için camelCase alias */
    public static function getShipTypes(array $params = []): array { return self::get_ship_types($params); }
}
