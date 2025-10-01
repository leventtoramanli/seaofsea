<?php
require_once __DIR__ . '/FileHandler.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';
require_once __DIR__ . '/../core/log.php';

class UploadHandler
{
    /**
     * Action: upload_image
     * Params:
     *  - type: 'user' | 'cover' | 'company' (default: 'user')
     *  - addWatermark: bool (optional)
     *  - deleteOld: bool (optional; default false)
     *  - file: $_FILES['file']
     */
    public static function upload_image($params)
    {
        $auth = Auth::requireAuth();
        $userId = (int)$auth['user_id'];
        $crud  = new Crud($userId);
        $files = $_FILES['file'] ?? null;

        if (!$files) {
            Logger::error('No file uploaded', ['user_id' => $userId, 'FILES' => $_FILES]);
            return Response::error('No file uploaded.');
        }

        // Tür -> klasör ve kolon eşleşmesi
        $type   = $params['type'] ?? 'user'; // 'user', 'cover', 'company'
        $folder = match ($type) {
            'cover'   => 'user/covers',
            'company' => 'companies/logo',
            default   => 'user/user',
        };
        $column = match ($type) {
            'cover'   => 'cover_image',
            'company' => 'company_logo',
            default   => 'user_image',
        };

        // Eski görseli DB GÜNCELLEMEDEN ÖNCE çek (sonradan değişmeden)
        $existingRow = $crud->read('users', ['id' => $userId], ['id', $column], false);
        $oldImage    = $existingRow[$column] ?? null;

        $fileHandler = new FileHandler();

        // Yükleme seçenekleri
        $addWatermark = filter_var($params['addWatermark'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $uploadOpts = [
            'folder'       => $folder,
            'prefix'       => $type . '_' . $userId . '_',
            'resize'       => true,
            'addWatermark' => $addWatermark,
        ];

        // Dosyayı yükle
        $uploadResult = $fileHandler->upload($files, $uploadOpts);
        if (!($uploadResult['success'] ?? false)) {
            Logger::error('Upload failed', ['user_id' => $userId, 'detail' => $uploadResult]);
            return Response::error($uploadResult['error'] ?? 'Upload failed.');
        }

        $filename = $uploadResult['filename'] ?? null;
        if (!$filename) {
            // Teoride olmaz; yine de savunmacı yaklaşım
            Logger::error('Upload result missing filename', ['user_id' => $userId, 'result' => $uploadResult]);
            return Response::error('Upload failed (no filename).');
        }

        // DB güncellemesi
        $ok = $crud->update('users', [$column => $filename], ['id' => $userId]);
        if (!$ok) {
            // Rollback: yeni dosyayı sil
            $fileHandler->delete("$folder/$filename");
            Logger::error('DB update failed after upload', [
                'user_id'  => $userId,
                'column'   => $column,
                'filename' => $filename,
            ]);
            return Response::error('Upload succeeded but DB update failed.');
        }

        // İstenmişse eski görseli sil (eski != yeni ise)
        $deleteOld = filter_var($params['deleteOld'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($deleteOld && $oldImage && $oldImage !== $filename) {
            // Hızlı ve net: doğrudan sil (aynı kullanıcı kolonunda başka referans yok varsayımı)
            // İsterseniz burada "başka referans var mı" kontrolü de eklenebilir.
            $fileHandler->delete("$folder/$oldImage");
        }

        Logger::info('Upload success', [
            'user_id'  => $userId,
            'type'     => $type,
            'filename' => $filename,
            'folder'   => $folder,
            'deleted_old' => ($deleteOld && $oldImage && $oldImage !== $filename),
        ]);

        // Standart zarf ile dön (uploadResult içeriğini data olarak yolluyoruz)
        return Response::success($uploadResult, 'Upload successful.');
    }
}
