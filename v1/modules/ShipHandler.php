<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';

class ShipHandler
{
    public static function get_ship_types(array $params = []): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $q       = isset($params['q']) ? trim((string)$params['q']) : '';
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(1000, max(1, (int)($params['per_page'] ?? 200)));
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $bind  = [];
        if ($q !== '') {
            $where[]    = '(name LIKE :q OR description LIKE :q)';
            $bind[':q'] = '%'.$q.'%';
        }
        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        // LIMIT/OFFSET'i integer olarak enjekte ediyoruz (bind ETMİYORUZ)
        $limit = (int)$perPage;
        $off   = (int)$offset;
        $sql   = "SELECT id, name, description
                  FROM ship_types
                  $whereSql
                  ORDER BY name ASC
                  LIMIT $limit OFFSET $off";

        $rows = $crud->query($sql, $bind) ?: [];

        return [
            'success' => true,
            'message' => 'OK',
            'data'    => $rows,   // Flutter tarafında deepDataList ile uyumlu
            'code'    => 200,
        ];
    }

    public static function getShipTypes(array $params = []): array
    {
        return self::get_ship_types($params);
    }
}
