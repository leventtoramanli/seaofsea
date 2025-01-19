<?php
namespace App\Handlers;

class ImageResizeHandler
{
    private int $defaultMaxSize = 1920; // Varsayılan maksimum boyut
    private string $outputFormat = 'png'; // Varsayılan çıktı formatı (PNG)

    /**
     * Resmi yeniden boyutlandır.
     * 
     * @param string $filePath Kaynak resmin yolu.
     * @param string $outputDir Çıkış dizini.
     * @param int|null $maxSize Maksimum genişlik/yükseklik (varsayılan: 1920).
     * @return string|null Yeni oluşturulan dosyanın yolu veya null.
     */
    public function resizeImage(string $filePath, string $outputDir, ?int $maxSize = null): ?string
    {
        $maxSize = $maxSize ?? $this->defaultMaxSize;

        // Resim türünü belirleme
        $imageInfo = getimagesize($filePath);
        if (!$imageInfo) {
            return null; // Geçersiz resim dosyası
        }

        [$originalWidth, $originalHeight, $imageType] = $imageInfo;

        // Kaynak resmi oluşturma
        $sourceImage = match ($imageType) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($filePath),
            IMAGETYPE_PNG => imagecreatefrompng($filePath),
            IMAGETYPE_GIF => imagecreatefromgif($filePath),
            default => null,
        };

        if (!$sourceImage) {
            return null; // Desteklenmeyen resim türü
        }

        // Oran hesaplama
        $aspectRatio = $originalWidth / $originalHeight;
        if ($originalWidth > $originalHeight) {
            $newWidth = $maxSize;
            $newHeight = (int)($maxSize / $aspectRatio);
        } else {
            $newHeight = $maxSize;
            $newWidth = (int)($maxSize * $aspectRatio);
        }

        // Yeni boyutlandırılmış resmi oluştur
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Saydamlığı koruma (PNG ve GIF için)
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparentColor = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
            imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparentColor);
        }

        // Resmi yeniden boyutlandır
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

        // Çıktı dosyasını belirleme
        $outputFilePath = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('resized_') . '.png';

        // Resmi kaydet
        imagepng($resizedImage, $outputFilePath);

        // Belleği temizle
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $outputFilePath;
    }
}
