<?php

class FileHandler
{
    private string $baseUploadDir;
    private static Logger $logger;

    public function __construct(string $uploadDir = null)
    {
        $this->baseUploadDir = $uploadDir ?? __DIR__ . '/../../uploads';
        $this->baseUploadDir = rtrim($this->baseUploadDir, '/') . '/';

        if (!isset(self::$logger)) {
            self::$logger = Logger::getInstance();
        }

        if (!is_dir($this->baseUploadDir)) {
            mkdir($this->baseUploadDir, 0777, true);
        }
    }

    public function upload(array $file, array $options = []): array
    {
        $allowedTypes = $options['allowedTypes'] ?? ['image/jpeg', 'image/png', 'application/pdf'];
        $customFolder = $options['folder'] ?? '';
        $rename = $options['rename'] ?? true;
        $prefix = $options['prefix'] ?? '';
        $resize = $options['resize'] ?? false;
        $maxWidth = $options['maxWidth'] ?? 1920;
        $maxHeight = $options['maxHeight'] ?? 1920;

        if (!in_array($file['type'], $allowedTypes)) {
            return $this->error('Invalid file type', $file['type']);
        }

        $folderPath = $this->createFolderPath($customFolder);
        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0777, true);
        }

        $safeName = $this->sanitizeFileName($file['name']);
        if ($rename) {
            $extension = pathinfo($safeName, PATHINFO_EXTENSION);
            $baseName = pathinfo($safeName, PATHINFO_FILENAME);
            $safeName = $prefix . $baseName . '_' . uniqid() . '.' . $extension;
        }

        $targetPath = $folderPath . $safeName;

        $moveSuccess = is_uploaded_file($file['tmp_name'])
    ? move_uploaded_file($file['tmp_name'], $targetPath)
    : copy($file['tmp_name'], $targetPath);

        if ($moveSuccess) {
            // 🔧 Resimse ve resize istendiyse
            if ($resize && $this->isImage($file['type'])) {
                $resizedPath = $this->resizeImage($targetPath, $maxWidth, $maxHeight);
                if (!$resizedPath) {
                    return $this->error('Image resize failed', $safeName);
                }

                $targetPath = $resizedPath;
                $safeName = basename($resizedPath);
            }

            return [
                'success' => true,
                'filename' => $safeName,
                'path' => $targetPath,
                'url' => $this->getUrl($customFolder . '/' . $safeName)
            ];
        }

        return $this->error('Failed to move uploaded file', $file['name']);
    }
    private function resizeImage(string $path, int $maxWidth, int $maxHeight): string|false
    {
        $info = getimagesize($path);
        if (!$info) {
            return false;
        }

        [$width, $height] = $info;
        $mime = $info['mime'];
        $ratio = min($maxWidth / $width, $maxHeight / $height, 1);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);

        switch ($mime) {
            case 'image/jpeg': $src = imagecreatefromjpeg($path);
                break;
            case 'image/png':  $src = imagecreatefrompng($path);
                break;
            default: return false;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $newPath = preg_replace('/\.(jpg|jpeg|png)$/i', '.png', $path);
        $result = imagepng($dst, $newPath);

        imagedestroy($src);
        imagedestroy($dst);

        if ($newPath !== $path && file_exists($path)) {
            unlink($path);
        }

        return $result ? $newPath : false;
    }

    // ✅ MIME türü kontrolü
    private function isImage(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png']);
    }

    public function delete(string $relativePath): array
    {
        $fullPath = $this->baseUploadDir . ltrim($relativePath, '/');

        if (file_exists($fullPath)) {
            unlink($fullPath);
            self::$logger->info("File deleted", ['path' => $relativePath]);
            return ['success' => true];
        }

        return $this->error('File not found', ['path' => $relativePath]);
    }

    public function listFiles(string $subFolder = ''): array
    {
        $path = $this->createFolderPath($subFolder);

        if (!is_dir($path)) {
            return [];
        }

        return array_values(array_diff(scandir($path), ['.', '..']));
    }

    private function createFolderPath(string $subFolder): string
    {
        return rtrim($this->baseUploadDir . ltrim($subFolder, '/'), '/') . '/';
    }

    private function sanitizeFileName(string $fileName): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', basename($fileName));
    }

    private function getUrl(string $relativePath): string
    {
        $config = require __DIR__ . '/../config/config.php'; // config yolunu senin sistemine göre ayarla
        $baseUrl = $config['app']['url'] ?? '';
        $relativePath = ltrim($relativePath, '/');
        return $baseUrl . '/uploads/' . $relativePath;
    }

    private function error(string $message, $details = []): array
    {
        self::$logger->error($message, $details);
        return ['success' => false, 'error' => $message];
    }
}
