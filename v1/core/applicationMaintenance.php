<?php
// tasks/maintenance.php
require_once __DIR__.'/../v1/core/crud.php';
require_once __DIR__.'/../v1/core/Auth.php'; // sadece bootstrap için; Auth kullanılmıyor
require_once __DIR__.'/../v1/core/logla.php';

$mode = $argv[1] ?? '';
$actorId = 1; // sistem kullanıcı id’niz (log için)
$crud = new Crud((int)$actorId);

switch ($mode) {
    case 'docshares_close_after_30d':
        // Kapanışı 30 günü geçen ilanlar → paylaşımları kapat
        $sql = "
            UPDATE user_document_shares s
            JOIN applications a ON s.grantee_type='application' AND s.grantee_id=a.id
            JOIN job_posts jp   ON jp.id=a.job_post_id
            SET s.revoked_at = NOW()
            WHERE jp.closed_at IS NOT NULL
              AND jp.closed_at < (NOW() - INTERVAL 30 DAY)
              AND s.revoked_at IS NULL
        ";
        $crud->query($sql, []);
        echo "OK docshares_close_after_30d\n";
        break;

    case 'cleanup_finalized_apps_1y':
        // Final durumlar + 1 yıl → applications temizliği
        $sql = "
            DELETE a
            FROM applications a
            WHERE a.status IN ('hired','rejected','withdrawn')
              AND a.created_at < (NOW() - INTERVAL 1 YEAR)
        ";
        $crud->query($sql, []);
        echo "OK cleanup_finalized_apps_1y\n";
        break;

    default:
        echo "Usage:\n";
        echo "  php tasks/maintenance.php docshares_close_after_30d\n";
        echo "  php tasks/maintenance.php cleanup_finalized_apps_1y\n";
        exit(1);
}
