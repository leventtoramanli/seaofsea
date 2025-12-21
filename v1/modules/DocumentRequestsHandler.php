<?php
// v1/modules/DocumentRequestsHandler.php
require_once __DIR__.'/../core/auth.php';
require_once __DIR__.'/../core/crud.php';
require_once __DIR__.'/../core/logla.php';

class DocumentRequestsHandler
{
    // --- helpers ---
    private static function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }

    private static function reqInt($v, string $name) {
        $i = (int)($v ?? 0); if ($i<=0) return ['success'=>false,'message'=>"$name is required",'code'=>422];
        return ['ok'=>$i];
    }
    private static function reqArray($v, string $name) {
        if (!is_array($v) || empty($v)) return ['success'=>false,'message'=>"$name is required",'code'=>422];
        return ['ok'=>$v];
    }

    private static function audit(Crud $crud, int $actorId, string $etype, int $eid, string $action, array $meta=[]): void {
        try{
            $crud->create('audit_events', [
                'actor_id'   => $actorId,
                'entity_type'=> $etype,
                'entity_id'  => $eid,
                'action'     => $action,
                'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => self::nowUtc(),
            ]);
        }catch(\Throwable $e){ /* swallow */ }
    }

    private static function require_int(mixed $val, string $name){
        $n = (int)($val ?? 0);
        return ($n>0) ? ['ok'=>$n] : ['success'=>false,'message'=>"$name is required",'code'=>422];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=create_request
    // params: application_id:int, due_at?:string(UTC), items:[{code,label,required,accept_types,expires_at?}]
    public static function create_request(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        // Uygulama ve şirket yetkisi
        $app = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id','user_id'], false);
        if (!$app) return ['success'=>false,'message'=>'Application not found','code'=>404];
        Gate::check('recruitment.app.review', (int)$app['company_id']);

        $items = $p['items'] ?? [];
        if (!is_array($items) || count($items)===0) {
            return ['success'=>false,'message'=>'items is required','code'=>422];
        }

        $dueAt = isset($p['due_at']) && is_string($p['due_at']) ? trim($p['due_at']) : null;

        // Header
        $rid = $crud->create('document_requests', [
            'application_id' => (int)$app['id'],
            'company_id'     => (int)$app['company_id'],
            'user_id'        => (int)$app['user_id'],
            'status'         => 'open',
            'due_at'         => $dueAt ?: null,
            'created_by'     => (int)$auth['user_id'],
            'created_at'     => self::nowUtc(),
            'updated_at'     => self::nowUtc(),
        ]);
        if (!$rid) return ['success'=>false,'message'=>'Create header failed','code'=>500];

        // Items
        $okCount = 0;
        foreach ($items as $it) {
            $code   = trim((string)($it['code'] ?? ''));
            $label  = trim((string)($it['label'] ?? ''));
            $req    = (int)($it['required'] ?? 1);
            $types  = isset($it['accept_types']) ? (string)$it['accept_types'] : null; // e.g. "pdf,jpg,png"
            $expAt  = isset($it['expires_at']) ? trim((string)$it['expires_at']) : null;

            if ($code==='' || $label==='') continue;

            $iid = $crud->create('document_request_items', [
                'request_id'   => (int)$rid,
                'code'         => $code,
                'label'        => $label,
                'required'     => $req ? 1 : 0,
                'accept_types' => $types,
                'expires_at'   => $expAt ?: null,
                'status'       => 'pending',  // pending|approved|rejected
                'created_at'   => self::nowUtc(),
            ]);
            if ($iid) $okCount++;
        }

        self::audit($crud, (int)$auth['user_id'], 'document_request', (int)$rid, 'create', [
            'application_id'=>(int)$app['id'], 'items'=>$okCount, 'due_at'=>$dueAt
        ]);

        // // NOTIF: request_created  (alıcı: applicant)
        return ['success'=>true,'message'=>'Created','data'=>['id'=>(int)$rid,'items_inserted'=>$okCount],'code'=>200];
    }

    // ------------------------------------------------------------
    // GET/POST: module=document_requests action=list_requests
    // params: application_id:int
    public static function list_requests(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success'])) return $appId;

        $app = $crud->read('applications', ['id'=>$appId['ok']], ['id','company_id','user_id'], false);
        if (!$app) return ['success'=>false,'message'=>'Application not found','code'=>404];

        // Yetki: sahibi veya şirket reviewer
        $isOwner = ((int)$app['user_id'] === (int)$auth['user_id']);
        if (!$isOwner) Gate::check('recruitment.app.review', (int)$app['company_id']);

        $rows = $crud->query("
            SELECT
              r.id, r.status, r.due_at, r.created_at, r.updated_at,
              COUNT(i.id)                                         AS item_count,
              SUM(i.status='approved')                             AS approved_count,
              SUM(i.status='rejected')                             AS rejected_count,
              SUM(i.status='pending')                              AS pending_count
            FROM document_requests r
            LEFT JOIN document_request_items i ON i.request_id = r.id
            WHERE r.application_id = :aid
            GROUP BY r.id
            ORDER BY COALESCE(r.updated_at, r.created_at) DESC, r.id DESC
        ", [':aid'=>$appId['ok']]) ?: [];

        // Items ayrıntısı istenirse (opsiyonel param: with_items=1)
        $with = (int)($p['with_items'] ?? 0) === 1;
        $itemsByReq = [];
        if ($with && $rows) {
            $ids = array_column($rows, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $ilist = $crud->query("
                SELECT id, request_id, code, label, required, accept_types, expires_at, status, linked_udv_id, created_at, reviewed_at, review_note
                FROM document_request_items
                WHERE request_id IN ($ph)
                ORDER BY id ASC
            ", $ids) ?: [];
            foreach ($ilist as $r) $itemsByReq[(int)$r['request_id']][] = $r;
        }

        // hydrate items
        if ($with) {
            foreach ($rows as &$r) $r['items'] = $itemsByReq[(int)$r['id']] ?? [];
        }

        return ['success'=>true,'message'=>'OK','data'=>['items'=>$rows],'code'=>200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=approve_item
    // params: item_id:int, review_note?:string
    public static function approve_item(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $iid = self::require_int($p['item_id'] ?? null, 'item_id');
        if (isset($iid['success'])) return $iid;

        $it = $crud->read('document_request_items', ['id'=>$iid['ok']], ['id','request_id','status'], false);
        if (!$it) return ['success'=>false,'message'=>'Item not found','code'=>404];

        $req = $crud->read('document_requests', ['id'=>$it['request_id']], ['id','application_id','company_id','status'], false);
        if (!$req) return ['success'=>false,'message'=>'Request not found','code'=>404];
        Gate::check('recruitment.app.review', (int)$req['company_id']);

        if (($it['status'] ?? '') === 'approved') {
            return ['success'=>true,'message'=>'No change','data'=>['id'=>$iid['ok']],'code'=>200];
        }

        $ok = $crud->update('document_request_items', [
            'status'      => 'approved',
            'reviewed_at' => self::nowUtc(),
            'review_note' => isset($p['review_note']) ? (string)$p['review_note'] : null,
        ], ['id'=>$iid['ok']]);
        if (!$ok) return ['success'=>false,'message'=>'Approve failed','code'=>500];

        $crud->update('document_requests', ['updated_at'=>self::nowUtc()], ['id'=>$req['id']]);

        self::audit($crud, (int)$auth['user_id'], 'document_request_item', (int)$iid['ok'], 'approve', ['request_id'=>(int)$req['id']]);
        // // NOTIF: doc_review (approved)  (alıcı: applicant)

        return ['success'=>true,'message'=>'Approved','data'=>['id'=>$iid['ok']],'code'=>200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=reject_item
    // params: item_id:int, reason?:string
    public static function reject_item(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $iid = self::require_int($p['item_id'] ?? null, 'item_id');
        if (isset($iid['success'])) return $iid;

        $it = $crud->read('document_request_items', ['id'=>$iid['ok']], ['id','request_id','status'], false);
        if (!$it) return ['success'=>false,'message'=>'Item not found','code'=>404];

        $req = $crud->read('document_requests', ['id'=>$it['request_id']], ['id','application_id','company_id','status'], false);
        if (!$req) return ['success'=>false,'message'=>'Request not found','code'=>404];
        Gate::check('recruitment.app.review', (int)$req['company_id']);

        if (($it['status'] ?? '') === 'rejected') {
            return ['success'=>true,'message'=>'No change','data'=>['id'=>$iid['ok']],'code'=>200];
        }

        $ok = $crud->update('document_request_items', [
            'status'      => 'rejected',
            'reviewed_at' => self::nowUtc(),
            'review_note' => isset($p['reason']) ? (string)$p['reason'] : null,
        ], ['id'=>$iid['ok']]);
        if (!$ok) return ['success'=>false,'message'=>'Reject failed','code'=>500];

        $crud->update('document_requests', ['updated_at'=>self::nowUtc()], ['id'=>$req['id']]);

        self::audit($crud, (int)$auth['user_id'], 'document_request_item', (int)$iid['ok'], 'reject', ['request_id'=>(int)$req['id']]);
        // // NOTIF: doc_review (rejected)  (alıcı: applicant)

        return ['success'=>true,'message'=>'Rejected','data'=>['id'=>$iid['ok']],'code'=>200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=close_request
    // params: request_id:int, reason?:string
    public static function close_request(array $p): array {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $rid = self::require_int($p['request_id'] ?? null, 'request_id');
        if (isset($rid['success'])) return $rid;

        $req = $crud->read('document_requests', ['id'=>$rid['ok']], ['id','company_id','status'], false);
        if (!$req) return ['success'=>false,'message'=>'Request not found','code'=>404];

        Gate::check('recruitment.app.review', (int)$req['company_id']);

        if (($req['status'] ?? '') === 'closed') {
            return ['success'=>true,'message'=>'Already closed','data'=>['id'=>$rid['ok']],'code'=>200];
        }

        $ok = $crud->update('document_requests', [
            'status'     => 'closed',
            'closed_at'  => self::nowUtc(),
            'closed_note'=> isset($p['reason']) ? (string)$p['reason'] : null,
            'updated_at' => self::nowUtc(),
        ], ['id'=>$rid['ok']]);

        if (!$ok) return ['success'=>false,'message'=>'Close failed','code'=>500];

        self::audit($crud, (int)$auth['user_id'], 'document_request', (int)$rid['ok'], 'close', [
            'reason'=>$p['reason'] ?? null
        ]);
        // // NOTIF: request_closed (alıcı: applicant)

        return ['success'=>true,'message'=>'Closed','data'=>['id'=>$rid['ok']],'code'=>200];
    }
}
