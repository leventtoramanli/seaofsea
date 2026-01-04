<?php
// v1/modules/DocumentRequestsHandler.php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/logla.php';

class DocumentRequestsHandler
{
    // --- helpers ---
    private static function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function reqInt($v, string $name)
    {
        $i = (int) ($v ?? 0);
        if ($i <= 0)
            return ['success' => false, 'message' => "$name is required", 'code' => 422];
        return ['ok' => $i];
    }
    private static function reqArray($v, string $name)
    {
        if (!is_array($v) || empty($v))
            return ['success' => false, 'message' => "$name is required", 'code' => 422];
        return ['ok' => $v];
    }

    private static function audit(Crud $crud, int $actorId, string $etype, int $eid, string $action, array $meta = []): void
    {
        try {
            $crud->create('audit_events', [
                'actor_id' => $actorId,
                'entity_type' => $etype,
                'entity_id' => $eid,
                'action' => $action,
                'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'created_at' => self::nowUtc(),
            ]);
        } catch (\Throwable $e) { /* swallow */
        }
    }

    private static function require_int(mixed $val, string $name)
    {
        $n = (int) ($val ?? 0);
        return ($n > 0) ? ['ok' => $n] : ['success' => false, 'message' => "$name is required", 'code' => 422];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=create_request
    // params: application_id:int, due_at?:string(UTC), items:[{code,label,required,accept_types,expires_at?}]
    public static function create_request(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success']))
            return $appId;

        $app = $crud->read('applications', ['id' => $appId['ok']], ['id', 'company_id', 'user_id'], false);
        if (!$app)
            return ['success' => false, 'message' => 'Application not found', 'code' => 404];

        Gate::check('recruitment.app.review', (int) $app['company_id']);

        $items = $p['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return ['success' => false, 'message' => 'items is required', 'code' => 422];
        }

        $dueAt = isset($p['due_at']) && is_string($p['due_at']) ? trim($p['due_at']) : null;

        $title = trim((string) ($p['title'] ?? 'Document request'));
        if ($title === '')
            $title = 'Document request';

        $note = isset($p['note']) ? trim((string) $p['note']) : null;
        if ($note === '')
            $note = null;

        $rid = $crud->create('document_requests', [
            'application_id' => (int) $app['id'],
            'company_id' => (int) $app['company_id'],
            'requested_by' => (int) $auth['user_id'],
            'title' => $title,
            'note' => $note,
            'due_at' => $dueAt ?: null,
            'status' => 'open',
            'created_at' => self::nowUtc(),
            'updated_at' => self::nowUtc(),
        ]);

        if (!$rid)
            return ['success' => false, 'message' => 'Create header failed', 'code' => 500];

        $okCount = 0;
        foreach ($items as $it) {
            $docType = trim((string) ($it['doc_type'] ?? $it['code'] ?? ''));
            $docType = strtolower($docType);
            if ($docType === '')
                continue;

            $docNote = isset($it['note']) ? trim((string) $it['note']) : null;
            if ($docNote === '')
                $docNote = null;

            $iid = $crud->create('document_request_items', [
                'request_id' => (int) $rid,
                'doc_type' => $docType,
                'note' => $docNote,
                'status' => 'pending',
                'created_at' => self::nowUtc(),
            ]);

            if ($iid)
                $okCount++;
        }

        self::audit($crud, (int) $auth['user_id'], 'document_request', (int) $rid, 'create', [
            'application_id' => (int) $app['id'],
            'items' => $okCount,
            'due_at' => $dueAt,
        ]);

        self::pushTimeline(
            $crud,
            (int) $app['id'],
            (int) $auth['user_id'],
            (int) $app['user_id'],
            'company_to_candidate',
            'doc_request',
            'Document request created'
        );

        self::pushNotification(
            $crud,
            (int) $app['user_id'],
            'doc_request_created',
            'Document requested',
            'A company requested documents for your application.',
            ['application_id' => (int) $app['id'], 'request_id' => (int) $rid]
        );

        return ['success' => true, 'message' => 'Created', 'data' => ['id' => (int) $rid, 'items_inserted' => $okCount], 'code' => 200];
    }

    // ------------------------------------------------------------
    // GET/POST: module=document_requests action=list_requests
    // params: application_id:int
    public static function list_requests(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $appId = self::require_int($p['application_id'] ?? null, 'application_id');
        if (isset($appId['success']))
            return $appId;

        $app = $crud->read('applications', ['id' => $appId['ok']], ['id', 'company_id', 'user_id'], false);
        if (!$app)
            return ['success' => false, 'message' => 'Application not found', 'code' => 404];

        $isOwner = ((int) $app['user_id'] === (int) $auth['user_id']);
        if (!$isOwner)
            Gate::check('recruitment.app.review', (int) $app['company_id']);

        $rows = $crud->query("
                SELECT
                r.id, r.status, r.due_at, r.created_at, r.updated_at,
                COUNT(i.id) AS item_count,
                SUM(i.status='approved') AS approved_count,
                SUM(i.status='rejected') AS rejected_count,
                SUM(i.status='pending')  AS pending_count,
                SUM(i.status='attached') AS attached_count
                FROM document_requests r
                LEFT JOIN document_request_items i ON i.request_id = r.id
                WHERE r.application_id = :aid
                GROUP BY r.id
                ORDER BY COALESCE(r.updated_at, r.created_at) DESC, r.id DESC
            ", [':aid' => $appId['ok']]) ?: [];

        $with = (int) ($p['with_items'] ?? 0) === 1;
        if ($with && $rows) {
            $ids = array_column($rows, 'id');
            $ph = implode(',', array_fill(0, count($ids), '?'));

            $ilist = $crud->query("
                SELECT
                    id, request_id, doc_type, note, status,
                    attached_document_id, attached_version_id,
                    checked_by, checked_at, created_at
                FROM document_request_items
                WHERE request_id IN ($ph)
                ORDER BY id ASC
                ", $ids) ?: [];

            $by = [];
            foreach ($ilist as $it) {
                $by[(int) $it['request_id']][] = $it;
            }

            foreach ($rows as &$r) {
                $r['items'] = $by[(int) $r['id']] ?? [];
            }
        }

        return ['success' => true, 'message' => 'OK', 'data' => ['items' => $rows], 'code' => 200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=approve_item
    // params: item_id:int, review_note?:string
    public static function approve_item(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $iid = self::require_int($p['item_id'] ?? null, 'item_id');
        if (isset($iid['success']))
            return $iid;

        $it = $crud->read('document_request_items', ['id' => $iid['ok']], ['id', 'request_id', 'status'], false);
        if (!$it)
            return ['success' => false, 'message' => 'Item not found', 'code' => 404];

        $req = $crud->read('document_requests', ['id' => $it['request_id']], ['id', 'application_id', 'company_id', 'status'], false);
        if (!$req)
            return ['success' => false, 'message' => 'Request not found', 'code' => 404];

        Gate::check('recruitment.app.review', (int) $req['company_id']);

        if (($it['status'] ?? '') === 'approved') {
            return ['success' => true, 'message' => 'No change', 'data' => ['id' => $iid['ok']], 'code' => 200];
        }

        $ok = $crud->update('document_request_items', [
            'status' => 'approved',
            'checked_by' => (int) $auth['user_id'],
            'checked_at' => self::nowUtc(),
        ], ['id' => $iid['ok']]);

        if (!$ok)
            return ['success' => false, 'message' => 'Approve failed', 'code' => 500];

        $crud->update('document_requests', ['updated_at' => self::nowUtc()], ['id' => (int) $req['id']]);

        self::audit($crud, (int) $auth['user_id'], 'document_request_item', (int) $iid['ok'], 'approve', ['request_id' => (int) $req['id']]);

        // Eğer tüm itemlar approved olduysa request status = approved
        $c = $crud->query("
                SELECT
                COUNT(*) AS total,
                SUM(status='approved') AS okc
                FROM document_request_items
                WHERE request_id = :r
            ", [':r' => (int) $req['id']]) ?: [];

        $total = (int) ($c[0]['total'] ?? 0);
        $okc = (int) ($c[0]['okc'] ?? 0);

        if ($total > 0 && $total === $okc) {
            $crud->update('document_requests', [
                'status' => 'approved',
                'updated_at' => self::nowUtc(),
            ], ['id' => (int) $req['id']]);

            // applicant id -> applications.user_id
            $appRow = $crud->read('applications', ['id' => (int) $req['application_id']], ['id', 'user_id'], false);
            $appUserId = (int) ($appRow['user_id'] ?? 0);

            if ($appUserId > 0) {
                self::pushTimeline(
                    $crud,
                    (int) $req['application_id'],
                    (int) $auth['user_id'],
                    $appUserId,
                    'company_to_candidate',
                    'doc_request',
                    'All requested documents were approved.'
                );

                self::pushNotification(
                    $crud,
                    $appUserId,
                    'doc_request_completed',
                    'Request completed',
                    'All requested documents were approved.',
                    ['request_id' => (int) $req['id'], 'application_id' => (int) $req['application_id']]
                );
            }
        }

        return ['success' => true, 'message' => 'Approved', 'data' => ['id' => $iid['ok']], 'code' => 200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=reject_item
    // params: item_id:int, reason?:string
    public static function reject_item(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $iid = self::require_int($p['item_id'] ?? null, 'item_id');
        if (isset($iid['success']))
            return $iid;

        $it = $crud->read('document_request_items', ['id' => $iid['ok']], ['id', 'request_id', 'status'], false);
        if (!$it)
            return ['success' => false, 'message' => 'Item not found', 'code' => 404];

        $req = $crud->read('document_requests', ['id' => $it['request_id']], ['id', 'application_id', 'company_id', 'status'], false);
        if (!$req)
            return ['success' => false, 'message' => 'Request not found', 'code' => 404];

        Gate::check('recruitment.app.review', (int) $req['company_id']);

        if (($it['status'] ?? '') === 'rejected') {
            return ['success' => true, 'message' => 'No change', 'data' => ['id' => $iid['ok']], 'code' => 200];
        }

        $ok = $crud->update('document_request_items', [
            'status' => 'rejected',
            'checked_by' => (int) $auth['user_id'],
            'checked_at' => self::nowUtc(),
        ], ['id' => $iid['ok']]);

        if (!$ok)
            return ['success' => false, 'message' => 'Reject failed', 'code' => 500];

        // request status -> rejected (en az 1 reject varsa)
        $crud->update('document_requests', [
            'status' => 'rejected',
            'updated_at' => self::nowUtc(),
        ], ['id' => (int) $req['id']]);

        self::audit($crud, (int) $auth['user_id'], 'document_request_item', (int) $iid['ok'], 'reject', [
            'request_id' => (int) $req['id'],
            'reason' => $p['reason'] ?? null,
        ]);

        $appRow = $crud->read('applications', ['id' => (int) $req['application_id']], ['id', 'user_id'], false);
        $appUserId = (int) ($appRow['user_id'] ?? 0);

        if ($appUserId > 0) {
            $noteTxt = trim((string) ($p['reason'] ?? ''));
            $msg = $noteTxt !== '' ? ("A document was rejected: " . $noteTxt) : "A requested document was rejected.";

            self::pushTimeline(
                $crud,
                (int) $req['application_id'],
                (int) $auth['user_id'],
                $appUserId,
                'company_to_candidate',
                'system',
                $msg
            );

            self::pushNotification(
                $crud,
                $appUserId,
                'doc_item_rejected',
                'Document rejected',
                $noteTxt !== '' ? $noteTxt : 'A requested document was rejected. Please re-upload.',
                ['request_item_id' => (int) $iid['ok'], 'request_id' => (int) $req['id'], 'application_id' => (int) $req['application_id']]
            );
        }

        return ['success' => true, 'message' => 'Rejected', 'data' => ['id' => $iid['ok']], 'code' => 200];
    }

    // ------------------------------------------------------------
    // POST: module=document_requests action=close_request
    // params: request_id:int, reason?:string
    public static function close_request(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int) $auth['user_id']);

        $rid = self::require_int($p['request_id'] ?? null, 'request_id');
        if (isset($rid['success']))
            return $rid;

        $req = $crud->read('document_requests', ['id' => $rid['ok']], ['id', 'application_id', 'company_id', 'status'], false);
        if (!$req)
            return ['success' => false, 'message' => 'Request not found', 'code' => 404];

        Gate::check('recruitment.app.review', (int) $req['company_id']);

        if (($req['status'] ?? '') === 'closed') {
            return ['success' => true, 'message' => 'Already closed', 'data' => ['id' => $rid['ok']], 'code' => 200];
        }

        $ok = $crud->update('document_requests', [
            'status' => 'closed',
            'updated_at' => self::nowUtc(),
        ], ['id' => $rid['ok']]);

        if (!$ok)
            return ['success' => false, 'message' => 'Close failed', 'code' => 500];

        self::audit($crud, (int) $auth['user_id'], 'document_request', (int) $rid['ok'], 'close', [
            'reason' => $p['reason'] ?? null,
        ]);

        $appRow = $crud->read('applications', ['id' => (int) $req['application_id']], ['id', 'user_id'], false);
        $appUserId = (int) ($appRow['user_id'] ?? 0);

        if ($appUserId > 0) {
            self::pushTimeline(
                $crud,
                (int) $req['application_id'],
                (int) $auth['user_id'],
                $appUserId,
                'company_to_candidate',
                'doc_request',
                'Document request was closed.'
            );

            self::pushNotification(
                $crud,
                $appUserId,
                'doc_request_closed',
                'Request closed',
                'The document request was closed by the company.',
                ['request_id' => (int) $req['id'], 'application_id' => (int) $req['application_id']]
            );
        }

        return ['success' => true, 'message' => 'Closed', 'data' => ['id' => $rid['ok']], 'code' => 200];
    }

    private static function pushTimeline(
        Crud $crud,
        int $appId,
        ?int $fromUserId,
        ?int $toUserId,
        string $direction,
        string $type,
        string $content
    ): void {
        // application_messages: type = text|doc_request|system
        $crud->create('application_messages', [
            'application_id' => (int) $appId,
            'from_user_id' => $fromUserId !== null ? (int) $fromUserId : null,
            'to_user_id' => $toUserId !== null ? (int) $toUserId : null,
            'direction' => $direction,
            'type' => $type,
            'content' => $content,
            'created_at' => self::nowUtc(),
        ]);
    }

    private static function pushNotification(
        Crud $crud,
        int $userId,
        string $type,
        string $title,
        string $body,
        array $meta = []
    ): void {
        $crud->create('notifications', [
            'user_id' => (int) $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'meta_json' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            'is_read' => 0,
            'created_at' => self::nowUtc(),
        ]);
    }
    private static function getItemContext(Crud $crud, int $itemId): ?array
    {
        $rows = $crud->query("
        SELECT
            dr.id          AS request_id,
            dr.application_id,
            dr.company_id,
            dr.user_id      AS applicant_user_id
        FROM document_request_items dri
        JOIN document_requests dr ON dr.id = dri.request_id
        WHERE dri.id = :i
        LIMIT 1
    ", [':i' => $itemId]);

        if (!$rows || !is_array($rows) || count($rows) === 0)
            return null;
        return $rows[0];
    }
    private static function getRequestContext(Crud $crud, int $requestId): ?array
    {
        $rows = $crud->query("
        SELECT id AS request_id, application_id, company_id, user_id AS applicant_user_id
        FROM document_requests
        WHERE id = :r
        LIMIT 1
    ", [':r' => $requestId]);

        if (!$rows || count($rows) === 0)
            return null;
        return $rows[0];
    }
    private static function recalcRequestStatus(Crud $crud, int $requestId): void
    {
        $r = $crud->read('document_requests', ['id' => $requestId], ['status'], false);
        if (!$r)
            return;
        $st = (string) ($r['status'] ?? '');
        if ($st === 'closed' || $st === 'expired')
            return;

        $rows = $crud->query("
        SELECT
          SUM(status='rejected') AS rej_cnt,
          SUM(status='approved') AS appr_cnt,
          SUM(status='attached') AS att_cnt,
          SUM(status='pending')  AS pend_cnt,
          COUNT(*)               AS total_cnt
        FROM document_request_items
        WHERE request_id = :r
    ", [':r' => $requestId]);

        $rej = (int) ($rows[0]['rej_cnt'] ?? 0);
        $appr = (int) ($rows[0]['appr_cnt'] ?? 0);
        $att = (int) ($rows[0]['att_cnt'] ?? 0);
        $tot = (int) ($rows[0]['total_cnt'] ?? 0);

        $newStatus = 'open';
        if ($rej > 0)
            $newStatus = 'rejected';
        else if ($tot > 0 && $appr === $tot)
            $newStatus = 'approved';
        else if (($att + $appr) > 0)
            $newStatus = 'uploaded';

        $crud->update('document_requests', [
            'status' => $newStatus,
            'updated_at' => self::nowUtc(),
        ], ['id' => $requestId]);
    }
}
