<?php
// v1/modules/DocumentsHandler.php
require_once __DIR__.'/../core/auth.php';
require_once __DIR__.'/../core/crud.php';
require_once __DIR__.'/../core/logla.php';

class DocumentsHandler {
  // 1. Kullanıcının kendi evraklarını listele (current + expiry meta)
  public static function list_user_docs(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $page    = max(1, (int)($p['page'] ?? 1));
    $perPage = max(1, min(100, (int)($p['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $sql = "
      SELECT d.id, d.doc_type, d.title, d.current_version_id,
             v.id AS version_id, v.version_no, v.mime, v.filesize, v.issued_at, v.expires_at
      FROM user_documents d
      LEFT JOIN user_document_versions v ON v.id = d.current_version_id
      WHERE d.user_id=:u
      ORDER BY d.id DESC
      LIMIT $perPage OFFSET $offset";
    $rows = $crud->query($sql, [':u'=>$userId]) ?: [];
    $tot  = (int)($crud->scalar("SELECT COUNT(*) FROM user_documents WHERE user_id=:u", [':u'=>$userId]) ?? 0);

    return ['success'=>true,'message'=>'OK','data'=>[
      'items'=>$rows,'total'=>$tot,'page'=>$page,'per_page'=>$perPage,'pages'=>$tot? (int)ceil($tot/$perPage):0
    ],'code'=>200];
  }

  // 2. Yeni sürüm yükle (yoksa document kartı aç)
  public static function upload_new_version(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $docType = trim((string)($p['doc_type'] ?? ''));
    $title   = trim((string)($p['title'] ?? $docType));
    if ($docType === '') return ['success'=>false,'message'=>'doc_type required','code'=>422];

    // Basit örnek: dosya zaten sunucuya yüklendi varsay (FileHandler ile). Burada path/mime/sha alıyoruz:
    $filePath = (string)($p['file_path'] ?? '');
    $mime     = (string)($p['mime'] ?? 'application/octet-stream');
    $filesize = (int)   ($p['filesize'] ?? 0);
    $sha256   = (string)($p['sha256'] ?? '');

    if ($filePath==='') return ['success'=>false,'message'=>'file_path required','code'=>422];

    $doc = $crud->row("SELECT * FROM user_documents WHERE user_id=:u AND doc_type=:t LIMIT 1", [':u'=>$userId, ':t'=>$docType]);
    if (!$doc) {
      $crud->exec("INSERT INTO user_documents (user_id, doc_type, title) VALUES (:u,:t,:title)", [':u'=>$userId, ':t'=>$docType, ':title'=>$title ?: $docType]);
      $docId = (int)$crud->lastId();
      $versionNo = 1;
    } else {
      $docId = (int)$doc['id'];
      $versionNo = (int)($crud->scalar("SELECT COALESCE(MAX(version_no),0)+1 FROM user_document_versions WHERE document_id=:d", [':d'=>$docId]) ?? 1);
    }

    $crud->exec("
      INSERT INTO user_document_versions
        (document_id, version_no, file_path, file_sha256, mime, filesize, issued_at, expires_at)
      VALUES (:d,:vn,:fp,:sha,:mime,:fs,:issued,:expires)",
      [
        ':d'=>$docId, ':vn'=>$versionNo, ':fp'=>$filePath, ':sha'=>$sha256,
        ':mime'=>$mime, ':fs'=>$filesize,
        ':issued'=>($p['issued_at'] ?? null), ':expires'=>($p['expires_at'] ?? null),
      ]
    );
    $verId = (int)$crud->lastId();
    $crud->exec("UPDATE user_documents SET current_version_id=:vid WHERE id=:d", [':vid'=>$verId, ':d'=>$docId]);

    return ['success'=>true,'message'=>'Uploaded','data'=>['document_id'=>$docId,'version_id'=>$verId,'version_no'=>$versionNo],'code'=>200];
  }

  // 3. Bir evrağı bir başvuruya paylaş (consent)
  public static function share_to_application(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $documentId   = (int)($p['document_id'] ?? 0);
    $applicationId= (int)($p['application_id'] ?? 0);
    $scope        = (string)($p['scope'] ?? 'view');
    if ($documentId<=0 || $applicationId<=0) return ['success'=>false,'message'=>'invalid ids','code'=>422];

    // Sahiplik
    $own = $crud->scalar("SELECT COUNT(*) FROM user_documents WHERE id=:d AND user_id=:u", [':d'=>$documentId, ':u'=>$userId]);
    if (!$own) return ['success'=>false,'message'=>'not owner','code'=>403];

    // Tekilleştir
    $crud->exec("
      INSERT INTO user_document_shares (document_id, grantee_type, grantee_id, scope, granted_by, consent_at, expires_at)
      VALUES (:d,'application',:gid,:scope,:by, NOW(), :exp)
      ON DUPLICATE KEY UPDATE scope=VALUES(scope), expires_at=VALUES(expires_at), revoked_at=NULL
    ", [':d'=>$documentId, ':gid'=>$applicationId, ':scope'=>$scope, ':by'=>$userId, ':exp'=>($p['expires_at'] ?? null)]);

    return ['success'=>true,'message'=>'Shared','data'=>[],'code'=>200];
  }

  public static function revoke_share(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $shareId = (int)($p['share_id'] ?? 0);
    if ($shareId<=0) return ['success'=>false,'message'=>'share_id required','code'=>422];

    // Kullanıcıya ait paylaşım mı?
    $ok = $crud->scalar("
      SELECT COUNT(*) FROM user_document_shares s
      JOIN user_documents d ON d.id=s.document_id
      WHERE s.id=:sid AND d.user_id=:u", [':sid'=>$shareId, ':u'=>$userId]);
    if (!$ok) return ['success'=>false,'message'=>'not allowed','code'=>403];

    $crud->exec("UPDATE user_document_shares SET revoked_at=NOW() WHERE id=:sid", [':sid'=>$shareId]);
    return ['success'=>true,'message'=>'Revoked','data'=>[],'code'=>200];
  }

  public static function list_shares(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $documentId = (int)($p['document_id'] ?? 0);
    if ($documentId<=0) return ['success'=>false,'message'=>'document_id required','code'=>422];

    // Sahiplik
    $own = $crud->scalar("SELECT COUNT(*) FROM user_documents WHERE id=:d AND user_id=:u", [':d'=>$documentId, ':u'=>$userId]);
    if (!$own) return ['success'=>false,'message'=>'not owner','code'=>403];

    $rows = $crud->query("SELECT * FROM user_document_shares WHERE document_id=:d ORDER BY id DESC", [':d'=>$documentId]) ?: [];
    return ['success'=>true,'message'=>'OK','data'=>['items'=>$rows],'code'=>200];
  }

  // Talep maddesine mevcut evrağı bağla (aday tarafı)
  public static function link_existing_to_request_item(array $p): array {
    $auth   = Auth::requireAuth();
    $userId = (int)$auth['user_id'];
    $crud   = new Crud($userId);

    $itemId     = (int)($p['request_item_id'] ?? 0);
    $documentId = (int)($p['document_id'] ?? 0);
    $versionId  = (int)($p['version_id'] ?? 0);
    if ($itemId<=0 || $documentId<=0 || $versionId<=0) return ['success'=>false,'message'=>'invalid params','code'=>422];

    // Sahiplik
    $own = $crud->scalar("SELECT COUNT(*) FROM user_documents WHERE id=:d AND user_id=:u", [':d'=>$documentId, ':u'=>$userId]);
    if (!$own) return ['success'=>false,'message'=>'not owner','code'=>403];

    $item = $crud->row("SELECT dr.application_id, dri.status FROM document_request_items dri JOIN document_requests dr ON dr.id=dri.request_id WHERE dri.id=:i", [':i'=>$itemId]);
    if (!$item) return ['success'=>false,'message'=>'item not found','code'=>404];

    // Bağla
    $crud->exec("
      UPDATE document_request_items
      SET status='attached', attached_document_id=:d, attached_version_id=:v
      WHERE id=:i", [':d'=>$documentId, ':v'=>$versionId, ':i'=>$itemId]);

    // (Opsiyonel) şirket tarafına bildirim: “aday evrak yükledi”
    // // bununla: burada notification olayı olacak mı kullanıcıya sor
    return ['success'=>true,'message'=>'Attached','data'=>[],'code'=>200];
  }
}
