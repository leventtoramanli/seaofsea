<?php
declare(strict_types=1);

/**
 * positions_view.php
 * Read-only, grouped HTML view for company_positions.
 * Grouping: area (office/crew) -> department
 */

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('UTC');

// ---- DB CONFIG (ENV ile doldur veya sabitle) ----
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbName = getenv('DB_NAME') ?: 'seaofsea_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>DB Connection Error</h1><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

// ---- VERİYİ ÇEK ----
// Sıralama: area -> department -> sort -> name -> id
// area sırası: office, crew (diğerleri sona)
$sql = "
SELECT id, sort, name, category, description, permission_codes, created_at, department, area
FROM company_positions
ORDER BY
  FIELD(area, 'office','crew') ASC,
  COALESCE(area, 'zzz') ASC,
  COALESCE(department, 'zzz') ASC,
  sort ASC,
  name ASC,
  id ASC
";
$rows = $pdo->query($sql)->fetchAll();

// ---- GRUPLA ----
$grouped = [];
foreach ($rows as $r) {
    $area = $r['area'] ?? null;
    $dept = $r['department'] ?? null;
    $grouped[$area][$dept][] = $r;
}

// Yardımcılar
function h(?string $s): string {
    if ($s === null) return '<span class="null">NULL</span>';
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function prettyArea(?string $a): string {
    if ($a === null || $a === '') return '— (no area)';
    if ($a === 'crew') return 'Crew';
    if ($a === 'office') return 'Office';
    return ucfirst($a);
}
function prettyDept(?string $d): string {
    return ($d === null || $d === '') ? '— (no department)' : ucfirst($d);
}

// Bölüm içi sayacı
function countDept(array $deptRows): int { return count($deptRows); }

?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>company_positions — grouped view</title>
<style>
  :root {
    --bg: #0f172a;           /* slate-900 */
    --bg-card: #111827;      /* gray-900 */
    --text: #e5e7eb;         /* gray-200 */
    --muted: #9ca3af;        /* gray-400 */
    --accent: #38bdf8;       /* sky-400 */
    --table-stripe: #0b1220; /* darker row */
    --chip: #1f2937;         /* gray-800 */
    --green: #22c55e;
  }
  * { box-sizing: border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text); font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji"; }
  .container { max-width: 1200px; margin: 24px auto 80px; padding: 0 16px; }
  h1 { font-size: 28px; margin: 0 0 16px; }
  .toolbar { display:flex; gap:12px; align-items:center; margin: 12px 0 24px; flex-wrap:wrap; }
  .search { flex:1 1 320px; }
  .search input {
    width:100%; padding:10px 12px; border:1px solid #263149; background:#0b1020; color:var(--text); border-radius:8px;
  }
  .chip { display:inline-block; padding:4px 8px; border-radius:999px; background:var(--chip); color:var(--muted); font-size:12px; }
  details.area { margin: 18px 0; border:1px solid #1f2937; border-radius:10px; background:var(--bg-card); }
  details[open].area { outline:1px solid #263149; }
  summary.area-title { cursor:pointer; padding: 12px 14px; font-weight:600; font-size:16px; }
  .dept-wrap { padding: 6px 14px 20px; }
  .dept {
    margin: 14px 0 8px; display:flex; align-items:center; gap:8px; justify-content: space-between; flex-wrap:wrap;
  }
  .dept h3 { margin:0; font-size:15px; }
  .dept .meta { color:var(--muted); font-size:12px; }
  table {
    width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; border-radius:10px; border:1px solid #1f2937; background:#0a0f1f;
  }
  thead th {
    position: sticky; top: 0; background:#0d1529; color:#cbd5e1; text-align:left; padding:10px 10px; font-size:12px; letter-spacing:.02em;
    border-bottom:1px solid #1f2937;
  }
  tbody td { padding:10px 10px; vertical-align: top; border-bottom:1px solid #0f1a33; }
  tbody tr:nth-child(odd) { background: var(--table-stripe); }
  code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: 12px; }
  .json { white-space: pre-wrap; word-break: break-word; background:#0b1020; border:1px solid #1b2744; padding:6px 8px; border-radius:6px; color:#c7d2fe; }
  .muted { color: var(--muted); }
  .null { color:#9ca3af; font-style: italic; }
  .wrap { max-width: 520px; white-space: normal; word-break: break-word; }
  .narrow { max-width: 220px; }
  .id { color:#93c5fd; } /* blue-300 */
  .sort { color:#86efac; } /* green-300 */
  .badge { background:#0b1020; border:1px solid #1b2744; padding:2px 6px; border-radius:6px; font-size:12px; color:#cbd5e1; }
  .toc { margin: 6px 0 18px; display:flex; flex-wrap:wrap; gap:8px; }
  a { color: var(--accent); text-decoration: none; }
  a:hover { text-decoration: underline; }
  .controls { display:flex; gap:8px; flex-wrap:wrap; }
  button {
    background:#0b1020; border:1px solid #1b2744; color:#cbd5e1; padding:6px 10px; border-radius:8px; cursor:pointer;
  }
  button:hover { background:#0f162b; }
  .small { font-size:12px; }
</style>
</head>
<body>
<div class="container">
  <h1>company_positions <span class="chip">grouped by area → department</span></h1>

  <div class="toolbar">
    <div class="search">
      <input id="q" type="search" placeholder="Ara: name / category / description / permission_codes" autocomplete="off">
    </div>
    <div class="controls">
      <button type="button" class="small" onclick="toggleAll(true)">Tümünü aç</button>
      <button type="button" class="small" onclick="toggleAll(false)">Tümünü kapat</button>
    </div>
  </div>

  <?php
  if (empty($grouped)) {
      echo '<p class="muted">Kayıt bulunamadı.</p>';
  } else {
      // Table of contents (areas)
      echo '<div class="toc">';
      foreach ($grouped as $area => $depts) {
          $aid = 'area-' . preg_replace('~[^a-z0-9]+~i', '-', (string)$area);
          echo '<a href="#'.$aid.'"># '.prettyArea($area).'</a>';
      }
      echo '</div>';

      foreach ($grouped as $area => $depts) {
          $aid = 'area-' . preg_replace('~[^a-z0-9]+~i', '-', (string)$area);
          echo '<details class="area" open id="'.$aid.'"><summary class="area-title">'.prettyArea($area).'</summary>';
          echo '<div class="dept-wrap">';
          foreach ($depts as $dept => $items) {
              $count = countDept($items);
              $did = $aid . '-dept-' . preg_replace('~[^a-z0-9]+~i', '-', (string)$dept);
              echo '<div class="dept" id="'.$did.'">';
              echo '  <h3>' . prettyDept($dept) . ' <span class="badge">'.$count.'</span></h3>';
              echo '  <a class="small" href="#'.$did.'">#</a>';
              echo '</div>';

              echo '<div class="table-wrap">';
              echo '<table class="tbl" data-dept="'.h((string)$dept).'" data-area="'.h((string)$area).'">';
              echo '<thead><tr>';
              echo '<th style="width:70px">id</th>';
              echo '<th style="width:70px">sort</th>';
              echo '<th style="width:220px">name</th>';
              echo '<th style="width:160px">category</th>';
              echo '<th>description</th>';
              echo '<th style="width:240px">permission_codes</th>';
              echo '<th style="width:160px">created_at (UTC)</th>';
              echo '</tr></thead><tbody>';
              foreach ($items as $r) {
                  $id  = (int)$r['id'];
                  $sort= $r['sort'] !== null ? (int)$r['sort'] : null;
                  $nm  = $r['name'];
                  $cat = $r['category'];
                  $desc= $r['description'];
                  $perm= $r['permission_codes'];
                  $ts  = $r['created_at'];

                  echo '<tr>';
                  echo '<td class="id">'.$id.'</td>';
                  echo '<td class="sort">'.($sort===null ? '<span class="null">NULL</span>' : (string)$sort).'</td>';
                  echo '<td class="narrow">'.h($nm).'</td>';
                  echo '<td class="narrow">'.h($cat).'</td>';
                  echo '<td class="wrap">'.h($desc).'</td>';
                  echo '<td><pre class="json">'.h($perm).'</pre></td>';
                  echo '<td class="narrow">'.h($ts).'</td>';
                  echo '</tr>';
              }
              echo '</tbody></table></div>';
          }
          echo '</div></details>';
      }
  }
  ?>
</div>

<script>
  // Basit arama: tüm tablolar üzerinde satır bazlı filtre
  const q = document.getElementById('q');
  q?.addEventListener('input', function() {
    const term = (this.value || '').toLowerCase();
    document.querySelectorAll('table.tbl tbody tr').forEach(tr => {
      const txt = tr.innerText.toLowerCase();
      tr.style.display = term && !txt.includes(term) ? 'none' : '';
    });
  });

  function toggleAll(open) {
    document.querySelectorAll('details.area').forEach(d => { d.open = !!open; });
  }
</script>
</body>
</html>
