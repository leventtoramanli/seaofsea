<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class CertificateHandler
{
    // Router köprüleri
    public static function list(array $p = []): array   { return self::listCertificates($p); }
    public static function groups(array $p = []): array { return self::listGroups($p); }

    private static function listCertificates(array $p): array
    {
        // Auth::requireAuth(); // okuma public kalabilir
        $crud = new Crud(0, false);

        $q       = trim((string)($p['q'] ?? ''));
        $groupId = isset($p['group_id']) ? (int)$p['group_id'] : 0;

        $page    = max(1, (int)($p['page'] ?? 1));
        $perPage = max(1, min(200, (int)($p['perPage'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $bind  = [];

        if ($groupId > 0) {
            $where[] = 'group_id = :g';
            $bind[':g'] = $groupId;
        }
        if ($q !== '') {
            $where[] = '(name LIKE CONCAT("%", :q, "%") OR stcw_code LIKE CONCAT("%", :q, "%"))';
            $bind[':q'] = $q;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // total
        $trow = $crud->query("SELECT COUNT(*) AS c FROM certificates $whereSql", $bind);
        $total = (int)($trow[0]['c'] ?? 0);

        // LIMIT değerleri risksiz sayıya çevrildi (int)
        $offset = (int)$offset;
        $perPage = (int)$perPage;

        $rows = $crud->query("
            SELECT id, group_id, sort_order, name, stcw_code, datelimit, needs_all
            FROM certificates
            $whereSql
            ORDER BY COALESCE(group_id, 999), COALESCE(sort_order, 999999), name ASC
            LIMIT $offset, $perPage
        ", $bind) ?: [];

        return [
            'items' => array_map(fn($r) => [
                'id'        => (int)$r['id'],
                'group_id'  => isset($r['group_id']) ? (int)$r['group_id'] : null,
                'name'      => (string)$r['name'],
                'stcw_code' => $r['stcw_code'] ?? null,
                'datelimit' => isset($r['datelimit']) ? (int)$r['datelimit'] : null,
                'needs_all' => (bool)$r['needs_all'],
            ], $rows),
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    private static function listGroups(array $p): array
    {
        $crud = new Crud(0, false);
        $rows = $crud->query("
            SELECT group_id AS id, COUNT(*) AS cnt
            FROM certificates
            WHERE group_id IS NOT NULL
            GROUP BY group_id
            ORDER BY group_id ASC
        ") ?: [];

        return [
            'items' => array_map(fn($r) => [
                'id'    => (int)$r['id'],
                'count' => (int)$r['cnt'],
            ], $rows),
            'total' => count($rows),
        ];
    }
}
