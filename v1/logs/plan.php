<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="UTF-8" />
  <title>Recruitment Plan Roadmap</title>
  <style>
    :root {
      --bg: #0f172a;
      --card: #111827;
      --accent: #38bdf8;
      --accent-soft: #0ea5e9;
      --text: #e5e7eb;
      --muted: #9ca3af;
      --border: #1f2937;
      --done: #22c55e;
      --todo: #facc15;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: radial-gradient(circle at top left, #1f2937 0, var(--bg) 40%, #020617 100%);
      color: var(--text);
      min-height: 100vh;
      padding: 24px;
      display: flex;
      justify-content: center;
    }

    .container {
      width: 100%;
      max-width: 1200px;
    }

    h1 {
      margin-top: 0;
      margin-bottom: 4px;
      font-size: 26px;
      letter-spacing: 0.03em;
    }

    .subtitle {
      margin: 0 0 20px;
      color: var(--muted);
      font-size: 14px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 16px;
    }

    .card {
      background: linear-gradient(to bottom right, rgba(15, 23, 42, 0.95), rgba(15, 23, 42, 0.9));
      border-radius: 16px;
      border: 1px solid rgba(148, 163, 184, 0.2);
      padding: 16px 18px;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.7);
      backdrop-filter: blur(10px);
    }

    .card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .card-title {
      font-size: 16px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      border: 1px solid rgba(148, 163, 184, 0.4);
      color: var(--muted);
    }

    .badge-dot {
      width: 7px;
      height: 7px;
      border-radius: 999px;
      margin-right: 6px;
    }

    .badge-php .badge-dot {
      background: #f97316;
    }

    .badge-flutter .badge-dot {
      background: #38bdf8;
    }

    .status-pill {
      padding: 2px 10px;
      border-radius: 999px;
      font-size: 11px;
      border: 1px solid rgba(148, 163, 184, 0.5);
      color: var(--muted);
    }

    .status-ok {
      border-color: rgba(34, 197, 94, 0.6);
      color: var(--done);
    }

    .status-wip {
      border-color: rgba(250, 204, 21, 0.6);
      color: var(--todo);
    }

    .list-title {
      margin: 10px 0 6px;
      font-size: 13px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    ul.todo-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 6px;
      max-height: 260px;
      overflow-y: auto;
    }

    .todo-item {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      font-size: 13px;
      line-height: 1.4;
      padding: 6px 8px;
      border-radius: 8px;
      border: 1px solid transparent;
    }

    .todo-item.done {
      border-color: rgba(34, 197, 94, 0.25);
      background: rgba(22, 163, 74, 0.08);
    }

    .todo-item.next {
      border-color: rgba(250, 204, 21, 0.3);
      background: rgba(250, 204, 21, 0.03);
    }

    .todo-checkbox {
      margin-top: 2px;
    }

    .todo-text {
      flex: 1;
    }

    .todo-item.done .todo-text {
      text-decoration: line-through;
      text-decoration-thickness: 1px;
      color: rgba(148, 163, 184, 0.9);
    }

    .meta-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 10px;
      font-size: 11px;
      color: var(--muted);
    }

    .meta-chip {
      padding: 2px 8px;
      border-radius: 999px;
      border: 1px solid rgba(148, 163, 184, 0.4);
    }

    .footer {
      margin-top: 18px;
      font-size: 11px;
      color: var(--muted);
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
    }

    code {
      font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 11px;
      background: rgba(15, 23, 42, 0.8);
      padding: 2px 6px;
      border-radius: 6px;
      border: 1px solid rgba(148, 163, 184, 0.4);
    }

    .error {
      margin-top: 12px;
      padding: 8px 10px;
      border-radius: 8px;
      background: rgba(239, 68, 68, 0.08);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #fecaca;
      font-size: 13px;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1>Recruitment Roadmap</h1>
    <p class="subtitle">
      Bu sayfa, <code>plan.json</code> dosyasını okuyup basit bir TODO / DONE görünümü oluşturur.
    </p>

    <div id="error" class="error" style="display:none;"></div>

    <div class="grid">
      <div class="card" id="php-card">
        <div class="card-header">
          <div class="card-title">
            <span class="badge badge-php">
              <span class="badge-dot"></span> PHP / Backend
            </span>
          </div>
          <span id="php-status-pill" class="status-pill status-ok">Yükleniyor…</span>
        </div>

        <div class="list-title">Yapılanlar</div>
        <ul id="php-done" class="todo-list"></ul>

        <div class="list-title">Sıradaki Adımlar</div>
        <ul id="php-next" class="todo-list"></ul>

        <div class="meta-row">
          <span class="meta-chip" id="php-status-text"></span>
        </div>
      </div>

      <div class="card" id="flutter-card">
        <div class="card-header">
          <div class="card-title">
            <span class="badge badge-flutter">
              <span class="badge-dot"></span> Flutter / Frontend
            </span>
          </div>
          <span id="flutter-status-pill" class="status-pill status-wip">Yükleniyor…</span>
        </div>

        <div class="list-title">Yapılanlar</div>
        <ul id="flutter-done" class="todo-list"></ul>

        <div class="list-title">Sıradaki Adımlar</div>
        <ul id="flutter-next" class="todo-list"></ul>

        <div class="meta-row">
          <span class="meta-chip" id="flutter-status-text"></span>
        </div>
      </div>
    </div>

    <div class="footer">
      <span>
        Dosya: <code>plan.json</code> (aynı klasörde olmalı)
      </span>
      <span id="updated-at"></span>
    </div>
  </div>

  <?php
  $planJson = @file_get_contents('plan.json');
  if (!$planJson) {
    $planJson = '{}';
  }
  echo $planJson;
  ?>

  <script>
    async function loadPlan() {
      const errorBox = document.getElementById('error');
      try {
        const res = await fetch('plan.json', { cache: 'no-cache' });
        if (!res.ok) {
          throw new Error('plan.json okunamadı: HTTP ' + res.status);
        }
        const json = await res.json();
        renderPlan(json);
      } catch (e) {
        errorBox.style.display = 'block';
        errorBox.textContent = 'Hata: ' + e.message +
          ' (plan.json bu HTML ile aynı klasörde mi?)';
      }
    }

    function createItem(text, isDone) {
      const li = document.createElement('li');
      li.className = 'todo-item ' + (isDone ? 'done' : 'next');

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.disabled = true;
      cb.checked = isDone;
      cb.className = 'todo-checkbox';

      const span = document.createElement('span');
      span.className = 'todo-text';
      span.textContent = text;

      li.appendChild(cb);
      li.appendChild(span);
      return li;
    }

    function renderPlan(plan) {
      const php = plan.roadmap?.php || {};
      const flutter = plan.roadmap?.flutter || {};

      const phpDone = php.done || [];
      const phpNext = php.next || [];
      const flutterDone = flutter.done || [];
      const flutterNext = flutter.next || [];

      const phpDoneEl = document.getElementById('php-done');
      const phpNextEl = document.getElementById('php-next');
      const flDoneEl = document.getElementById('flutter-done');
      const flNextEl = document.getElementById('flutter-next');

      phpDone.forEach(t => phpDoneEl.appendChild(createItem(t, true)));
      phpNext.forEach(t => phpNextEl.appendChild(createItem(t, false)));

      flutterDone.forEach(t => flDoneEl.appendChild(createItem(t, true)));
      flutterNext.forEach(t => flNextEl.appendChild(createItem(t, false)));

      const phpStatusPill = document.getElementById('php-status-pill');
      const phpStatusText = document.getElementById('php-status-text');
      const flStatusPill = document.getElementById('flutter-status-pill');
      const flStatusText = document.getElementById('flutter-status-text');

      const phpStatus = php.status || 'unknown';
      phpStatusPill.textContent = phpStatus;
      if (phpStatus === 'mvp_backend_ready') {
        phpStatusPill.classList.add('status-ok');
      } else {
        phpStatusPill.classList.add('status-wip');
      }
      phpStatusText.textContent = 'Toplam: ' +
        phpDone.length + ' done / ' + phpNext.length + ' next';

      const flStatus = flutter.status || 'unknown';
      flStatusPill.textContent = flStatus;
      if (flStatus === 'frontend_rebuild_in_progress') {
        flStatusPill.classList.add('status-wip');
      } else {
        flStatusPill.classList.add('status-ok');
      }
      flStatusText.textContent = 'Toplam: ' +
        flutterDone.length + ' done / ' + flutterNext.length + ' next';

      const updatedAt = plan.notes?.updated_at;
      const updatedAtEl = document.getElementById('updated-at');
      if (updatedAt) {
        updatedAtEl.textContent = 'Son güncelleme: ' + updatedAt;
      }
    }

    const plan = <?php echo $planJson; ?>;
    renderPlan(plan);
  </script>
</body>

</html>