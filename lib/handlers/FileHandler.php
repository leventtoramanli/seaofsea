<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class FileHandler {
    private $uploadDir;
    private static $logger;

    public function __construct($uploadDir = __DIR__ . '/../../uploads') {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';

        // Logger yapılandırması
        if (!self::$logger) {
            self::$logger = new Logger('file_operations');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/file_operations.log', Logger::ERROR));
        }

        // Klasör yoksa oluştur
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    // Dosya yükleme
    public function uploadFile($file, $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf']) {
        // Dosya türü kontrolü
        if (!in_array($file['type'], $allowedTypes)) {
            $this->logError('Invalid file type', $file['type']);
            return [
                'success' => false,
                'error' => 'Invalid file type. Only JPEG, PNG, and PDF files are allowed.'
            ];
        }

        // Dosya adı güvenliği
        $fileName = $this->sanitizeFileName($file['name']);
        $targetPath = $this->uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return [
                'success' => true,
                'path' => $targetPath
            ];
        }

        $this->logError('Failed to upload file', $file['name']);
        return [
            'success' => false,
            'error' => 'Failed to upload file.'
        ];
    }

    // Dosya silme
    public function deleteFile($fileName) {
        $filePath = $this->uploadDir . $fileName;

        if (file_exists($filePath)) {
            unlink($filePath);
            return ['success' => true];
        }

        $this->logError('File not found', $fileName);
        return ['success' => false, 'error' => 'File not found.'];
    }

    // Yüklenen dosyaların listesi
    public function listFiles() {
        $files = array_diff(scandir($this->uploadDir), ['.', '..']);
        return array_values($files);
    }

    // Dosya adı güvenliği (sanitize)
    private function sanitizeFileName($fileName) {
        // Dosya adındaki özel karakterleri temizle
        return preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', basename($fileName));
    }

    // Hata loglama
    private function logError($message, $details) {
        self::$logger->error($message, ['details' => $details]);
    }
}
