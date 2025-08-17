<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/FileHandler.php';

class JobHandler
{
    public static function apply_job(array $p=[]): array { return self::apply($p); }

    private static function apply(array $p): array
    {
        $auth = Auth::requireAuth();
        $crud = new Crud((int)$auth['user_id']);

        $position = trim((string)($p['position'] ?? ''));
        $message  = trim((string)($p['message'] ?? ''));
        if ($position==='') return ['success'=>false, 'error'=>'position_required'];

        // base64 dosya varsa kaydet
        $savedFile = null;
        if (!empty($p['file_name']) && !empty($p['file_data'])) {
            $fn  = basename((string)$p['file_name']);
            $raw = base64_decode((string)$p['file_data'], true);
            if ($raw !== false) {
                $folder = 'job_applications';
                $fh = new FileHandler();
                // basit yazım:
                $path = $fh->createFolderPath($folder) . $fn;
                file_put_contents($path, $raw);
                $savedFile = $fn;
            }
        }

        $crud->create('job_applications', [
            'user_id'    => (int)$auth['user_id'],
            'position'   => $position,
            'message'    => $message,
            'file_name'  => $savedFile,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success'=>true];
    }
}
