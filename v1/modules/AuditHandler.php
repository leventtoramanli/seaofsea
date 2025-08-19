<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';

class AuditHandler
{
    public static function record(
        string $entityType,
        int $entityId,
        string $action,
        array $meta = [],
        ?int $actorId = null,
        ?string $ip = null,
        ?string $ua = null
    ): bool {
        // actorId yoksa mevcut kullanıcıyı al
        if ($actorId === null) {
            $auth = Auth::check();
            if ($auth) $actorId = (int)$auth['user_id'];
        }

        $crud = $actorId ? new Crud($actorId) : new Crud();
        $payload = [
            'actor_id'    => $actorId ?: null,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'ip'          => $ip,
            'user_agent'  => $ua,
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        return (bool)$crud->create('audit_events', $payload);
    }

    /* Basit listeleme: entity’ye göre son olaylar */
    public static function list(array $p = []): array
    {
        $auth = Auth::requireAuth();
        $uid  = (int)$auth['user_id'];
        $crud = new Crud($uid);

        $type = trim((string)($p['entity_type'] ?? ''));
        $eid  = (int)($p['entity_id'] ?? 0);
        if ($type === '' || $eid <= 0) {
            return ['items'=>[], 'error'=>'entity_type_and_id_required'];
        }

        // Yetki: entity’ye göre şirketi bul ve check et
        $companyId = null;
        if ($type === 'job_post') {
            $row = $crud->read('job_posts', ['id'=>$eid], ['company_id'], false);
            if (!$row) return ['items'=>[], 'error'=>'not_found'];
            $companyId = (int)$row['company_id'];
        } elseif ($type === 'job_application') {
            $row = $crud->read('job_applications', ['id'=>$eid], ['company_id'], false);
            if (!$row) return ['items'=>[], 'error'=>'not_found'];
            $companyId = (int)$row['company_id'];
        } else {
            return ['items'=>[], 'error'=>'unsupported_entity_type'];
        }

        $authorized = class_exists('PermissionService') && method_exists('PermissionService','hasPermission')
            ? ( PermissionService::hasPermission($uid, 'job.update', $companyId)
              || PermissionService::hasPermission($uid, 'job.applications.view', $companyId)
              || PermissionService::hasPermission($uid, 'job.applications.update', $companyId) )
            : true;
        if (!$authorized) return ['items'=>[], 'error'=>'not_authorized'];

        $items = $crud->read(
            'audit_events',
            ['entity_type' => $type, 'entity_id' => $eid],
            ['id','actor_id','action','meta','created_at','ip','user_agent'],
            true,
            ['created_at'=>'DESC'],
            [],
            ['limit'=>200]
        ) ?: [];

        foreach ($items as &$it) {
            if (!empty($it['meta'])) {
                $j = json_decode((string)$it['meta'], true);
                if (is_array($j)) $it['meta'] = $j;
            }
        }
        return ['items'=>$items];
    }
}
