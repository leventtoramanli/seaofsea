<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class CityHandler
{
    public static function list(array $p = []): array     { return self::listCities($p); }
    public static function get_city(array $p = []): array { return self::listCities($p); } // alias

    private static function listCities(array $p): array
    {
        // Auth::requireAuth(); // public kalabilir
        $crud = new Crud(0, false);

        $q     = trim((string)($p['q'] ?? ''));
        $iso3  = strtoupper(trim((string)($p['iso3'] ?? '')));
        $limit = max(1, min(200, (int)($p['limit'] ?? 100)));

        $where = [];
        $bind  = [];

        if ($q !== '') {
            $where[] = 'name LIKE CONCAT("%", :q, "%")';
            $bind[':q'] = $q;
        }
        if ($iso3 !== '') {
            $where[] = 'iso3 = :c';
            $bind[':c'] = $iso3;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $limit = (int)$limit;
        $rows = $crud->query("
            SELECT id, name, iso3
            FROM cities
            $whereSql
            ORDER BY name ASC
            LIMIT $limit
        ", $bind) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $name = (string)$r['name'];
            // Başlık düzeni: İlk harfler büyük
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
            $iso  = strtoupper((string)$r['iso3']);
            $items[] = [
                'id'    => (int)$r['id'],
                'name'  => $name,
                'iso3'  => $iso,
                'label' => $iso ? "$name ($iso)" : $name,
            ];
        }

        return ['cities' => $items];
    }
}
