<?php
// v1/modules/DocumentsHandler.php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/crud.php';
require_once __DIR__ . '/../core/logla.php';

class DocumentsHandler
{

  private static function nowUtc(): string
  {
    return gmdate('Y-m-d H:i:s');
  }

  private static function firstRow(Crud $crud, string $sql, array $params = []): ?array
  {
    $rows = $crud->query($sql, $params);
    if (!$rows || !is_array($rows) || count($rows) === 0)
      return null;
    return $rows[0];
  }

  private static function scalar(Crud $crud, string $sql, array $params = [])
  {
    $row = self::firstRow($crud, $sql, $params);
    if (!$row)
      return null;
    $vals = array_values($row);
    return $vals[0] ?? null;
  }
  // 1. Kullanıcının kendi evraklarını listele (current + expiry meta)
  public static function list_user_docs(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $page = max(1, (int) ($p['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($p['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $docType = trim((string) ($p['doc_type'] ?? ''));
    $where = "WHERE d.user_id=:u";
    $args = [':u' => $userId];

    if ($docType !== '') {
      $where .= " AND d.doc_type=:t";
      $args[':t'] = $docType;
    }

    $sql = "
      SELECT d.id, d.doc_type, d.title, d.current_version_id,
            v.id AS version_id, v.version_no, v.mime, v.filesize, v.issued_at, v.expires_at
      FROM user_documents d
      LEFT JOIN user_document_versions v ON v.id = d.current_version_id
      $where
      ORDER BY d.id DESC
      LIMIT $perPage OFFSET $offset";
    $rows = $crud->query($sql, $args) ?: [];
    $cond = ['user_id' => $userId];
    if ($docType !== '')
      $cond['doc_type'] = $docType;
    $tot = (int) ($crud->count('user_documents', $cond) ?? 0);

    return [
      'success' => true,
      'message' => 'OK',
      'data' => [
        'items' => $rows,
        'total' => $tot,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $tot ? (int) ceil($tot / $perPage) : 0
      ],
      'code' => 200
    ];
  }

  // 2. Yeni sürüm yükle (yoksa document kartı aç)
  public static function upload_new_version(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $docType = trim((string) ($p['doc_type'] ?? ''));
    $title = trim((string) ($p['title'] ?? $docType));
    if ($docType === '')
      return ['success' => false, 'message' => 'doc_type required', 'code' => 422];

    // Basit örnek: dosya zaten sunucuya yüklendi varsay (FileHandler ile). Burada path/mime/sha alıyoruz:
    $filePath = (string) ($p['file_path'] ?? '');
    $mime = (string) ($p['mime'] ?? 'application/octet-stream');
    $filesize = (int) ($p['filesize'] ?? 0);
    $sha256 = (string) ($p['sha256'] ?? '');

    if ($filePath === '')
      return ['success' => false, 'message' => 'file_path required', 'code' => 422];

    $doc = self::firstRow($crud, "
  SELECT id
  FROM user_documents
  WHERE user_id=:u AND doc_type=:t
  LIMIT 1
", [':u' => $userId, ':t' => $docType]);

    if (!$doc) {
      $docId = (int) $crud->create('user_documents', [
        'user_id' => $userId,
        'doc_type' => $docType,
        'title' => ($title ?: $docType),
      ]);
      if (!$docId)
        return ['success' => false, 'message' => 'create document failed', 'code' => 500];
      $versionNo = 1;
    } else {
      $docId = (int) $doc['id'];
      $vn = self::scalar($crud, "
    SELECT COALESCE(MAX(version_no),0)+1 AS vn
    FROM user_document_versions
    WHERE document_id=:d
  ", [':d' => $docId]);
      $versionNo = (int) ($vn ?? 1);
    }

    $verId = (int) $crud->create('user_document_versions', [
      'document_id' => $docId,
      'version_no' => $versionNo,
      'file_path' => $filePath,
      'file_sha256' => $sha256,
      'mime' => $mime,
      'filesize' => $filesize,
      'issued_at' => ($p['issued_at'] ?? null),
      'expires_at' => ($p['expires_at'] ?? null),
    ]);
    if (!$verId)
      return ['success' => false, 'message' => 'create version failed', 'code' => 500];

    $crud->update('user_documents', ['current_version_id' => $verId], ['id' => $docId]);

    return [
      'success' => true,
      'message' => 'Uploaded',
      'data' => [
        'document_id' => $docId,
        'version_id' => $verId,
        'version_no' => $versionNo
      ],
      'code' => 200
    ];
  }

  // 3. Bir evrağı bir başvuruya paylaş (consent)
  // 3. Bir evrağı bir başvuruya paylaş (consent)
  public static function share_to_application(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $documentId = (int) ($p['document_id'] ?? 0);
    $applicationId = (int) ($p['application_id'] ?? 0);
    $scope = trim((string) ($p['scope'] ?? 'view'));

    if ($documentId <= 0 || $applicationId <= 0) {
      return ['success' => false, 'message' => 'invalid ids', 'code' => 422];
    }

    // scope enum güvenliği (SQL enum: view|download|verify_only)
    $allowedScopes = ['view', 'download', 'verify_only'];
    if (!in_array($scope, $allowedScopes, true)) {
      $scope = 'view';
    }

    // 1) Doc sahipliği
    $own = (int) (self::scalar(
      $crud,
      "SELECT COUNT(*) AS c FROM user_documents WHERE id=:d AND user_id=:u",
      [':d' => $documentId, ':u' => $userId]
    ) ?? 0);

    if ($own <= 0) {
      return ['success' => false, 'message' => 'not owner', 'code' => 403];
    }

    // 2) Application sahipliği (KVKK güvenlik kilidi)
    $appOwn = (int) (self::scalar(
      $crud,
      "SELECT COUNT(*) AS c FROM applications WHERE id=:a AND user_id=:u",
      [':a' => $applicationId, ':u' => $userId]
    ) ?? 0);

    if ($appOwn <= 0) {
      return ['success' => false, 'message' => 'application not found or not yours', 'code' => 403];
    }

    // 3) existing share?
    $existing = self::firstRow($crud, "
    SELECT id
    FROM user_document_shares
    WHERE document_id=:d AND grantee_type='application' AND grantee_id=:gid
    LIMIT 1
  ", [':d' => $documentId, ':gid' => $applicationId]);

    $data = [
      'document_id' => $documentId,
      'grantee_type' => 'application',
      'grantee_id' => $applicationId,
      'scope' => $scope,
      'granted_by' => $userId,
      'consent_at' => self::nowUtc(),
      'expires_at' => ($p['expires_at'] ?? null),
      'revoked_at' => null,
    ];

    if ($existing) {
      $crud->update('user_document_shares', $data, ['id' => (int) $existing['id']]);
      $sid = (int) $existing['id'];
    } else {
      $sid = (int) $crud->create('user_document_shares', $data);
      if (!$sid) {
        return ['success' => false, 'message' => 'share create failed', 'code' => 500];
      }
    }

    return ['success' => true, 'message' => 'Shared', 'data' => ['share_id' => $sid], 'code' => 200];
  }

  public static function revoke_share(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $shareId = (int) ($p['share_id'] ?? 0);
    if ($shareId <= 0)
      return ['success' => false, 'message' => 'share_id required', 'code' => 422];

    // Kullanıcıya ait paylaşım mı?
    $ok = (int) (self::scalar($crud, "
        SELECT COUNT(*) AS c
        FROM user_document_shares s
        JOIN user_documents d ON d.id=s.document_id
        WHERE s.id=:sid AND d.user_id=:u
      ", [':sid' => $shareId, ':u' => $userId]) ?? 0);

    if ($ok <= 0)
      return ['success' => false, 'message' => 'not allowed', 'code' => 403];


    $crud->update('user_document_shares', ['revoked_at' => self::nowUtc()], ['id' => $shareId]);
    return ['success' => true, 'message' => 'Revoked', 'data' => [], 'code' => 200];
  }

  public static function list_shares(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $documentId = (int) ($p['document_id'] ?? 0);
    if ($documentId <= 0)
      return ['success' => false, 'message' => 'document_id required', 'code' => 422];

    // Sahiplik
    $own = (int) (self::scalar(
      $crud,
      "SELECT COUNT(*) AS c FROM user_documents WHERE id=:d AND user_id=:u",
      [':d' => $documentId, ':u' => $userId]
    ) ?? 0);
    if ($own <= 0)
      return ['success' => false, 'message' => 'not owner', 'code' => 403];

    $rows = $crud->query("SELECT * FROM user_document_shares WHERE document_id=:d ORDER BY id DESC", [':d' => $documentId]) ?: [];
    return ['success' => true, 'message' => 'OK', 'data' => ['items' => $rows], 'code' => 200];
  }

  // Talep maddesine mevcut evrağı bağla (aday tarafı)
  // Talep maddesine mevcut evrağı bağla (aday tarafı)
  public static function link_existing_to_request_item(array $p): array
  {
    $auth = Auth::requireAuth();
    $userId = (int) $auth['user_id'];
    $crud = new Crud($userId);

    $itemId = (int) ($p['request_item_id'] ?? 0);
    $documentId = (int) ($p['document_id'] ?? 0);
    $versionId = (int) ($p['version_id'] ?? 0); // opsiyonel (0 gelebilir)

    if ($itemId <= 0 || $documentId <= 0) {
      return ['success' => false, 'message' => 'invalid params', 'code' => 422];
    }

    // 1) Doc sahipliği
    $own = (int) (self::scalar(
      $crud,
      "SELECT COUNT(*) AS c FROM user_documents WHERE id=:d AND user_id=:u",
      [':d' => $documentId, ':u' => $userId]
    ) ?? 0);

    if ($own <= 0) {
      return ['success' => false, 'message' => 'not owner', 'code' => 403];
    }

    // 2) Item + Request + Application context (applicant = applications.user_id)
    $ctx = self::firstRow($crud, "
    SELECT
      dr.id              AS request_id,
      dr.application_id,
      dr.company_id,
      dr.requested_by    AS requested_by,
      a.user_id          AS applicant_user_id,
      dri.status         AS item_status,
      dri.doc_type       AS item_doc_type
    FROM document_request_items dri
    JOIN document_requests dr ON dr.id = dri.request_id
    JOIN applications a ON a.id = dr.application_id
    WHERE dri.id = :i
    LIMIT 1
  ", [':i' => $itemId]);

    if (!$ctx) {
      return ['success' => false, 'message' => 'item not found', 'code' => 404];
    }

    // Aday kendi application'ı için attach yapabilir
    if ((int) $ctx['applicant_user_id'] !== $userId) {
      return ['success' => false, 'message' => 'not allowed', 'code' => 403];
    }

    // Status koruması: approved item üzerine yazma yok (rejected/pending/attached allow)
    $curStatus = (string) ($ctx['item_status'] ?? '');
    if ($curStatus === 'approved') {
      return ['success' => false, 'message' => 'item already approved', 'code' => 409];
    }

    $applicationId = (int) ($ctx['application_id'] ?? 0);
    $companyId = (int) ($ctx['company_id'] ?? 0);
    $requestedBy = (int) ($ctx['requested_by'] ?? 0);
    $requestId = (int) ($ctx['request_id'] ?? 0);

    if ($applicationId <= 0 || $companyId <= 0 || $requestId <= 0) {
      return ['success' => false, 'message' => 'context missing', 'code' => 500];
    }

    // 3) versionId yoksa current_version_id kullan
    if ($versionId <= 0) {
      $cv = self::scalar($crud, "SELECT current_version_id FROM user_documents WHERE id=:d AND user_id=:u", [
        ':d' => $documentId,
        ':u' => $userId,
      ]);
      $versionId = (int) ($cv ?? 0);
      if ($versionId <= 0) {
        return ['success' => false, 'message' => 'document has no current version', 'code' => 422];
      }
    }

    // 4) Version doğrulama: version bu doc’a ait mi?
    $verOk = (int) (self::scalar($crud, "
    SELECT COUNT(*) AS c
    FROM user_document_versions
    WHERE id = :v AND document_id = :d
  ", [':v' => $versionId, ':d' => $documentId]) ?? 0);

    if ($verOk <= 0) {
      return ['success' => false, 'message' => 'invalid version', 'code' => 422];
    }

    // 5) Share kontrol: doc bu application’a share edilmiş mi?
    $shareOk = (int) (self::scalar($crud, "
    SELECT COUNT(*) AS c
    FROM user_document_shares
    WHERE document_id = :d
      AND grantee_type = 'application'
      AND grantee_id = :a
      AND revoked_at IS NULL
      AND (expires_at IS NULL OR expires_at > :now)
  ", [
      ':d' => $documentId,
      ':a' => $applicationId,
      ':now' => self::nowUtc(),
    ]) ?? 0);

    if ($shareOk <= 0) {
      return ['success' => false, 'message' => 'document not shared to this application', 'code' => 403];
    }

    // 6) Attach
    $ok = $crud->update('document_request_items', [
      'status' => 'attached',
      'attached_document_id' => $documentId,
      'attached_version_id' => $versionId,
    ], ['id' => $itemId]);

    if (!$ok) {
      return ['success' => false, 'message' => 'attach failed', 'code' => 500];
    }

    // Timeline
    $crud->create('application_messages', [
      'application_id' => $applicationId,
      'from_user_id' => $userId,
      'to_user_id' => null,
      'direction' => 'candidate_to_company',
      'type' => 'doc_request',
      'content' => 'Candidate attached a requested document.',
      'created_at' => self::nowUtc(),
    ]);

    // Notification -> request’i oluşturan kullanıcıya (requested_by)
    if ($requestedBy > 0) {
      $meta = [
        'target' => 'company_application_detail',
        'route_args' => [
          'company_id' => $companyId,
          'application_id' => $applicationId,
        ],
        'extra' => [
          'request_id' => $requestId,
          'request_item_id' => $itemId,
          'document_id' => $documentId,
          'version_id' => $versionId,
          'event' => 'doc_item_attached',
        ],
      ];

      $crud->create('notifications', [
        'user_id' => $requestedBy,
        'type' => 'doc_item_attached',
        'title' => 'Document uploaded',
        'body' => 'Candidate attached a requested document.',
        'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
        'is_read' => 0,
        'created_at' => self::nowUtc(),
      ]);
    }

    return ['success' => true, 'message' => 'Attached', 'data' => ['id' => $itemId], 'code' => 200];
  }
}
