<?php

require_once __DIR__ . '/FileHandler.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Crud.php';

class UploadHandler
{
    public static function upload_image($params)
    {
        $auth = Auth::requireAuth();
        $fileHandler = new FileHandler();
        $crud = new Crud($auth['user_id']);

        if (empty($_FILES['file'])) {
            Logger::error('No file uploaded. $_FILES:'. json_encode($_FILES), ['user_id' => $auth['user_id']]);
            Response::error('No file uploaded.');
            return;
        }

        $type = $params['type'] ?? 'user'; // 'user', 'cover', 'company'
        $folder = match ($type) {
            'cover' => 'user/covers',
            'company' => 'companies/logo',
            default => 'user/user',
        };
        $column = match ($type) {
            'cover' => 'cover_image',
            'company' => 'company_logo',
            default => 'user_image',
        };

        $uploadResult = $fileHandler->upload($_FILES['file'], [
            'folder' => $folder,
            'prefix' => $type .'_' . $auth['user_id'] . '_',
            'resize' => true,
            'addWatermark' => filter_var($params['addWatermark'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);

        if (!$uploadResult['success']) {
            Logger::error('Upload failed', $uploadResult);
            Response::error($uploadResult['error'] ?? 'Upload failed.');
            return;
        }

        $filename = $uploadResult['filename'];
        $updateSuccess = $crud->update('users', [$column => $filename], ['id' => $auth['user_id']]);

        if (!$updateSuccess) {
            Logger::error('DB update failed after upload', [
                'column' => $column,
                'filename' => $filename,
                'user_id' => $auth['user_id']
            ]);
            $fileHandler->delete("$folder/$filename");
            Response::error('Upload succeeded but DB update failed.');
            return;
        }

        $deleteOld = filter_var($params['deleteOld'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($deleteOld) {
            $existing = $crud->read('users', ['id' => $auth['user_id']]);
            if (!empty($existing)) {
                $oldImage = $existing[0][$column] ?? null;
                if ($oldImage && $oldImage !== $filename) {
                    $fileHandler->delete("$folder/$oldImage");
                }
            }
        }

        Logger::info('Upload success', [
            'user_id' => $auth['user_id'],
            'type' => $type,
            'filename' => $filename,
        ]);

        Response::success($uploadResult, 'Upload successful.');
    }
}
