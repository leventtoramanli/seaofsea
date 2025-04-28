<?php

namespace App\Handlers;

use Exception;

class ImageUploadHandler
{
    private $allowedFormats = ['jpg', 'jpeg', 'png', 'webp'];
    private $maxFileSize = 15 * 1024 * 1024; // Maksimum dosya boyutu
    private $uploadDir;
    private static $logger;

    public function __construct($uploadDir)
    {
        self::$logger = self::$logger ?? getLogger();
        $this->uploadDir = __DIR__ . '/../../' . $uploadDir;

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
            chmod($this->uploadDir, 0755);
        }
    }

    public function handleUpload($file, $imageBase64, $userId, $meta = [], $maxSize = 1920): string
    {
        if (!$userId) {
            throw new Exception('User ID is required.');
        }

        // 1. Yeni dosyayı yükle
        if ($file) {
            $fileName = $this->uploadImage($file, $userId, $meta);
        } elseif ($imageBase64) {
            $fileName = $this->uploadBase64Image($imageBase64, $userId, $meta);
        } else {
            throw new Exception('No file or Base64 data provided.');
        }

        // 2. Eski dosyayı sil
        $this->deleteOldImage($userId);

        // 3. Yeni dosya çözünürlük ayarı
        $this->resizeImage($this->uploadDir . DIRECTORY_SEPARATOR . $fileName, $maxSize);

        return $fileName;
    }
    public function handleUploadWithPrefix($file, $userId, $prefix, $meta = [], $maxSize = 1920): string {
        if (!$userId) {
            throw new Exception('User ID is required.');
        }
    
        if (!$file) {
            throw new Exception('No file provided.');
        }
    
        $this->validateImage($file);
    
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $prefix . '_' . $userId . '_' . time() . '.' . strtolower($extension);
    
        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;
    
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception("Failed to upload the file.");
        }
    
        $this->writeExifData($filePath, $meta);
        $this->resizeImage($filePath, $maxSize);
    
        return $fileName;
    }
    
    private function deleteOldImage($userId): void
    {
        if (empty($userId)) {
            throw new Exception('User ID is invalid.');
        }

        $crudHandler = new \CRUDHandler();
        $checkOldImage = $crudHandler->read('users', ['id' => $userId]);

        if (empty($checkOldImage)) {
            self::$logger->warning('No old image found for user.', ['userId' => $userId]);
            return;
        }
        $checkOldImage = json_decode(json_encode($checkOldImage), true);
        $oldImage = $checkOldImage[0]['cover_image'] ?? null;

        if (!$oldImage) {
            self::$logger->warning('Old image is null.', ['userId' => $userId]);
            return;
        }

        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $oldImage;

        if (file_exists($filePath)) {
            if (!unlink($filePath)) {
                self::$logger->error('Failed to delete file.', ['filePath' => $filePath]);
            } else {
                self::$logger->info('Old image deleted.', ['userId' => $userId, 'file' => $oldImage]);
            }
        } else {
            self::$logger->info('Old image file not found.', ['userId' => $userId, 'file' => $oldImage]);
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
            throw new Exception("File size exceeds the maximum limit of 15 MB.");
        }
    }

    public function uploadImage($file, $userId, $meta = []): string
    {
        $this->validateImage($file);
        $fileName = $this->generateFileName($file, $userId);
        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new Exception("Failed to upload the file.");
        }

        $this->writeExifData($filePath, $meta);
        return $fileName;
    }

    public function uploadBase64Image($base64Image, $userId, $meta = []): string
    {
        $imageData = base64_decode($base64Image);
        if ($imageData === false) {
            throw new Exception("Invalid base64 image data.");
        }

        $fileName = $this->generateFileNameFromBase64($userId);
        $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (file_put_contents($filePath, $imageData) === false) {
            throw new Exception("Failed to save base64 image.");
        }

        $this->writeExifData($filePath, $meta);
        return $fileName;
    }

    public function resizeImage(string $filePath, int $maxSize): void
    {
        $imageInfo = getimagesize($filePath);
        [$originalWidth, $originalHeight, $imageType] = $imageInfo;

        $aspectRatio = $originalWidth / $originalHeight;
        if ($originalWidth > $originalHeight) {
            $newWidth = $maxSize;
            $newHeight = (int)($maxSize / $aspectRatio);
        } else {
            $newHeight = $maxSize;
            $newWidth = (int)($maxSize * $aspectRatio);
        }

        $sourceImage = match ($imageType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => imagecreatefrompng($filePath),
            IMAGETYPE_GIF => imagecreatefromgif($filePath),
            default => throw new Exception('Unsupported image type.'),
        };

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
        }

        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $originalWidth,
            $originalHeight
        );

        imagepng($resizedImage, $filePath);
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);
    }

    private function generateFileName($file, $userId): string
    {
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        return $userId . '_' . time() . '.' . strtolower($extension);
    }

    private function generateFileNameFromBase64($userId): string
    {
        return $userId . '_' . time() . '.png';
    }

    private function writeExifData($filePath, $meta)
    {
        // EXIF yazma işlemi buraya eklenebilir
    }
}
