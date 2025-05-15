<?php
// Basit bağlantı – düzenlemek istersen burayı ayarla
$host = 'localhost';
$db   = 'seaofsea_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Bağlantıyı kur
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass);
} catch (PDOException $e) {
    die("Veritabanı bağlantısı başarısız: " . $e->getMessage());
}

// Departmanları çek (benzersiz)
$stmt = $pdo->query("SELECT DISTINCT department FROM company_positions WHERE department IS NOT NULL ORDER BY department ASC");
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Departman Listesi</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4; }
    ul { list-style-type: none; padding: 0; }
    li { background: white; margin: 5px 0; padding: 10px; border-radius: 4px; box-shadow: 0 0 5px #ccc; }
    h2 { margin-bottom: 15px; }
  </style>
</head>
<body>

<h2>📋 Departmanlar (<?= count($departments) ?> adet)</h2>

<ul>
  <?php foreach ($departments as $dept): ?>
    <li><?= htmlspecialchars($dept) ?></li>
  <?php endforeach; ?>
</ul>

</body>
</html>
