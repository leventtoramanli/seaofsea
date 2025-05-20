<?php
// Basit bağlantı – düzenlemek istersen burayı ayarla
$host = 'localhost';
$db   = 'seaofsea_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Bağlantı
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$pdo = new PDO($dsn, $user, $pass);

// Sayfalama ayarları
$limit = 365;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Toplam kayıt sayısı
$total = $pdo->query("SELECT COUNT(*) FROM certificates")->fetchColumn();
$totalPages = ceil($total / $limit);

// Verileri çek
$stmt = $pdo->prepare("SELECT * FROM certificates");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Company Positions (<?= $page ?> / <?= $totalPages ?>)</title>
  <style>
    body { font-family: sans-serif; background: #f9f9f9; padding: 20px; }
    table { width: 100%; border-collapse: collapse; background: white; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; text-align: left; }
    th { background: #eee; }
    h2 { margin-bottom: 10px; }
    .pagination a {
      margin: 0 4px; padding: 4px 8px; border: 1px solid #ccc;
      background: #fff; text-decoration: none; color: #333;
    }
    .pagination a.current {
      background: #333; color: white;
    }
  </style>
</head>
<body>

<h2>Company Positions (<?= $total ?> kayıt – Sayfa <?= $page ?> / <?= $totalPages ?>)</h2>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Category</th>
      <th>Department</th>
      <th>Area</th>
      <th>Description</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= htmlspecialchars($row['id']) ?></td>
      <td><?= htmlspecialchars($row['name']) ?></td>
      <td><?= htmlspecialchars($row['stcw_code']) ?></td>
      <td><?= htmlspecialchars($row['ship_type']) ?></td>
      <td><?= htmlspecialchars($row['datelimit']) ?></td>
      <td><?= htmlspecialchars($row['needs_all']) ?></td>
      <td><?= htmlspecialchars($row['medical_requirements']) ?></td>
      <td><?= htmlspecialchars($row['note']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="pagination">
  <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'current' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>

</body>
</html>