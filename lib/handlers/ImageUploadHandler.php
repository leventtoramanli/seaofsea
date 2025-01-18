<?php

namespace App\Handlers;

use Exception;

class ImageUploadHandler
{
    private $allowedFormats = ['jpg', 'jpeg', 'png', 'webp'];
    private $maxFileSize = 15 * 1024 * 1024; // 2 MB
    private $uploadDir;
    private $deleteOldImage = false;

    private static $logger;



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

    private function logInfo(string $message, array $data = [])
    {
        self::$logger->info($message, $data);
    }

    private function logError(string $message, Exception $exception = null)
    {
        $context = [];
        if ($exception) {
            $context['exception'] = [
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ];
        }
        self::$logger->error($message, $context);
    }

    public function __construct($uploadDir)
    {
        self::$logger = self::$logger ?? getLogger();
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
            throw new Exception("File size exceeds the maximum limit of 5 MB.");
        }
    }

    public function uploadImage($file, $userId, $meta = [], $oldImage = false)
    {
        try{
            $this->logInfo('Starting image upload.', ['userId' => $userId]);
            $this->validateImage($file);

            $fileName = $this->generateFileName($file, $userId);
            $filePath = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;

            // Eski dosyayı sil
            if ($oldImage && file_exists($this->uploadDir . DIRECTORY_SEPARATOR . $oldImage)) {
                if(file_exists($this->uploadDir . DIRECTORY_SEPARATOR . $oldImage)){
                    unlink($this->uploadDir . DIRECTORY_SEPARATOR . $oldImage);
                }
                $this->logInfo('Old image deleted.', ['file' => $oldImage]);
            }else{
                $this->logInfo('Old image not found.', ['file' => $oldImage]);
            }
            $this->logInfo('File already exists search.', ['file' => $oldImage]);

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception("Failed to upload the file.");
            }

            // Meta veriyi EXIF olarak dosyaya yaz
            $this->writeExifData($filePath, $meta);
            $this->logInfo('Image uploaded successfully.', ['file' => $fileName]);


            return $fileName;
        }
        catch (Exception $e) {
            $this->logError('Image upload failed.', $e);
            throw $e;
        }
    }

private function writeExifData($filePath, $meta)
{
    // EXIF yazma işlemi
    $exifData = [
        'Publisher' => $meta['Publisher'] ?? '',
        'Description' => $meta['Description'] ?? '',
        'Title' => $meta['Title'] ?? '',
        'Author' => $meta['Author'] ?? '',
        'UserId' => $meta['UserId'] ?? '',
    ];

    // EXIF yazmak için bir kütüphane veya manuel işleme eklenebilir.
    foreach ($exifData as $key => $value) {
        if (!empty($value)) {
            // EXIF yazma işlemi (örneğin exiftool ile)
            // shell_exec("exiftool -$key='$value' $filePath");
        }
    }
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
