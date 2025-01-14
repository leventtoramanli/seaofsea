<?php

namespace App\Handlers;

use Exception;

class ImageUploadHandler
{
    private $allowedFormats = ['jpg', 'jpeg', 'png', 'webp'];
    private $maxFileSize = 2 * 1024 * 1024; // 2 MB
    private $uploadDir;
    private $deleteOldImage = false;

    private function validateMetaData($file, $expectedMeta)
    {
        $metaData = exif_read_data($file['tmp_name']);
        if (!$metaData || !isset($metaData['UserComment'])) {
            throw new Exception('Image files are only accepted through the SeaOfSea system.');
        }

        if ($metaData['UserComment'] !== $expectedMeta) {
            throw new Exception('Meta data mismatch.');
        }
    }

    public function __construct($uploadDir)
    {
        $this->uploadDir = __DIR__ . '/../../' . $uploadDir;
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
            chmod($this->uploadDir, 0755);
        } else {
            $currentPermissions = substr(sprintf('%o', fileperms($this->uploadDir)), -4);
            if ($currentPermissions !== '0755') {
                chmod($this->uploadDir, 0755);
            }
        }
    }

    public function validateImage($file)
    {
        $fileSize = $file['size'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $this->allowedFormats)) {
            throw new Exception("Invalid file format. Allowed formats: " . implode(', ', $this->allowedFormats));
        }

        if ($fileSize > $this->maxFileSize) {
            throw new Exception("File size exceeds the maximum limit of 2 MB.");
        }
    }

    public function uploadImage($file, $userId, $expectedMeta, $oldImage = null)
    {
        $this->validateImage($file);
        $this->validateMetaData($file, $expectedMeta);

        $fileName = $this->generateFileName($file, $userId);
        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if ($this->deleteOldImage && $oldImage && file_exists($this->uploadDir . DIRECTORY_SEPARATOR . $oldImage)){
            if (!unlink($this->uploadDir . DIRECTORY_SEPARATOR . $oldImage)) {
                throw new Exception('Failed to delete the old image.');
            }
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception('Failed to upload the file.');
        }

        return $fileName;
    }

    private function generateFileName($file, $userId)
    {
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        return $userId . '_' . time() . '.' . $fileExtension;
    }

    public function getUploadPath()
    {
        return $this->uploadDir;
    }
}
