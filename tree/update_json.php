<?php
// JSON verisini al
$jsonData = file_get_contents('php://input');

// Dosya yolunu belirle
$filePath = 'tree.json';

// Dosyaya yaz
if (file_put_contents($filePath, $jsonData)) {
  echo 'Başarıyla kaydedildi!';
} else {
  http_response_code(500);
  echo 'Hata oluştu!';
}
?>
