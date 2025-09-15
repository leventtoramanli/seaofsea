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

        // --- Sayfalama: çoklu alias desteği
        $pageRaw    = $p['page'] ?? $p['p'] ?? 1;
        $perPageRaw = $p['perPage'] ?? $p['per_page'] ?? $p['limit'] ?? 50;

        $page    = max(1, (int)$pageRaw);
        $perPage = max(1, min(200, (int)$perPageRaw)); // üst sınır güvenlik için 200
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $bind  = [];

        if ($groupId > 0) {
            $where[]   = 'group_id = :g';
            $bind[':g'] = $groupId;
        }
        if ($q !== '') {
            $where[]    = '(name LIKE CONCAT("%", :q, "%") OR stcw_code LIKE CONCAT("%", :q, "%"))';
            $bind[':q'] = $q;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // total
        $trow  = $crud->query("SELECT COUNT(*) AS c FROM certificates $whereSql", $bind);
        $total = (int)($trow[0]['c'] ?? 0);

        // Geçersiz büyük offset'i son sayfaya çek
        if ($total > 0 && $offset >= $total) {
            $page   = (int)ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
        }

        // LIMIT değerlerini int'e sabitle
        $offset  = (int)$offset;
        $perPage = (int)$perPage;

        $rows = $crud->query("
            SELECT
                id,
                group_id,
                sort_order,
                name,
                stcw_code,
                datelimit,
                needs_all,
                note
            FROM certificates
            $whereSql
            ORDER BY COALESCE(group_id, 999),
                    COALESCE(sort_order, 999999),
                    name ASC
            LIMIT $offset, $perPage
        ", $bind) ?: [];

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'         => (int)$r['id'],
                'group_id'   => isset($r['group_id']) ? (int)$r['group_id'] : null,
                'sort_order' => isset($r['sort_order']) ? (int)$r['sort_order'] : null,
                'name'       => (string)$r['name'],
                'stcw_code'  => isset($r['stcw_code']) && $r['stcw_code'] !== null ? (string)$r['stcw_code'] : null,
                'datelimit'  => isset($r['datelimit']) ? (int)$r['datelimit'] : null,
                'needs_all'  => (bool)$r['needs_all'],
                'note'       => isset($r['note']) ? (string)$r['note'] : null,
            ];
        }

        $pages   = ($perPage > 0) ? (int)ceil($total / $perPage) : 1;
        $hasMore = $page < $pages;

        return [
            'items'   => $items,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
            'pages'   => $pages,
            'hasMore' => $hasMore,
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

    public static function getlist(array $p = []): array { return self::get_list($p); }

    private static function get_list(array $p): array
    {
        Auth::requireAuth();
        $crud = new Crud((int)Auth::requireAuth()['user_id']);

        $q       = trim((string)($p['q'] ?? ''));
        $groupId = isset($p['group_id']) ? (int)$p['group_id'] : null;
        $page    = max(1, (int)($p['page'] ?? 1));
        $per     = min(1000, max(1, (int)($p['per_page'] ?? 200)));
        $off     = ($page - 1) * $per;

        $where = '1=1';
        $bind  = [];
        if ($q !== '') {
            $where .= ' AND (name LIKE :q OR stcw_code LIKE :q)';
            $bind[':q'] = '%'.$q.'%';
        }
        if ($groupId) {
            $where .= ' AND group_id = :g';
            $bind[':g'] = $groupId;
        }

        $rows = $crud->query("
            SELECT id, name, stcw_code, group_id, sort_order, note
            FROM certificates
            WHERE $where
            ORDER BY group_id ASC, sort_order ASC, name ASC
            LIMIT :lim OFFSET :off
        ", array_merge($bind, [':lim'=>$per, ':off'=>$off])) ?: [];

        $total = (int)($crud->query("SELECT COUNT(*) c FROM certificates WHERE $where", $bind)[0]['c'] ?? 0);

        return [
            'success' => true,
            'message' => 'OK',
            'data' => [
                'items'     => $rows,
                'page'      => $page,
                'per_page'  => $per,
                'total'     => $total,
            ],
            'code' => 200,
        ];
    }
}
