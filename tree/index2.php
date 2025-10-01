<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SeaOfSea – ToDo & Dependency Map</title>
  <style>
    :root{--bg:#0f1115;--card:#171922;--muted:#aab3c5;--text:#e8ecf3;--accent:#69b7ff;--ok:#38c172;--warn:#ffb020;--bad:#ef5753}
    html,body{margin:0;padding:0;height:100%;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Noto Sans",sans-serif;background:var(--bg);color:var(--text)}
    header{position:sticky;top:0;z-index:5;background:linear-gradient(180deg,rgba(23,25,34,.95),rgba(23,25,34,.75));backdrop-filter:blur(6px);border-bottom:1px solid #1f2230}
    .container{max-width:1100px;margin:0 auto;padding:16px}
    .row{display:flex;gap:12px;flex-wrap:wrap}
    .card{background:var(--card);border:1px solid #202332;border-radius:14px;padding:14px}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    button,.btn{background:#22263a;border:1px solid #2b3147;color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer}
    button:hover{border-color:#3a4363}
    input,select,textarea{background:#0e1018;border:1px solid #24283b;color:var(--text);padding:8px;border-radius:10px;width:100%}
    label{font-size:12px;color:var(--muted)}
    h1{font-size:22px;margin:8px 0}
    h2{font-size:18px;margin:8px 0}
    .pill{display:inline-flex;gap:6px;align-items:center;border:1px solid #2b3147;background:#131625;padding:3px 8px;border-radius:999px;font-size:12px;color:#c9d4eb}
    .muted{color:var(--muted)}
    .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}
    .col-4{grid-column:span 4}
    .col-6{grid-column:span 6}
    .col-8{grid-column:span 8}
    .col-12{grid-column:span 12}
    details>summary{cursor:pointer;user-select:none}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #22263a;padding:8px;text-align:left;font-size:14px}
    .right{display:flex;justify-content:flex-end}
    .ok{color:var(--ok)} .warn{color:var(--warn)} .bad{color:var(--bad)}
    .tabbar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
    .tabbar button{padding:8px 10px}
    .tab-active{background:#2b3147}
    .foot{margin-top:12px;font-size:12px;color:var(--muted)}
    .kbd{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;background:#0d0f16;border:1px solid #232840;border-radius:8px;padding:1px 6px}
    .small{font-size:12px}
  </style>
</head>
<body>
  <header>
    <div class="container row" style="align-items:center;justify-content:space-between">
      <div>
        <h1>SeaOfSea – ToDo & Dependency Map</h1>
        <div class="muted small">Tek dosyalık tarayıcı uygulaması • JSON/CSV içe/dışa aktarım • FE↔BE/SQL eşlemesi</div>
      </div>
      <div class="toolbar">
        <input type="file" id="fileInput" accept="application/json"/>
        <button id="importBtn">JSON Yükle</button>
        <button id="exportJsonBtn">JSON Dışa Aktar</button>
        <button id="exportTodosCsvBtn">ToDo → CSV</button>
        <button id="exportDepsCsvBtn">Dependencies → CSV</button>
      </div>
    </div>
  </header>

  <main class="container">
    <div class="tabbar" id="tabbar"></div>
    <section id="view"></section>
  </main>

  <script>
  // --- Minimal in-memory state (persisted to localStorage) --------------------
  const defaultState = {
    project:{ name:"seaofsea (Flutter + PHP)", description:"Architecture & ToDo map", repos:[
      {id:"repo_flutter", name:"seaofsea_flutter", url:"https://github.com/leventtoramanli/seaofsea_flutter"},
      {id:"repo_php", name:"seaofsea", url:"https://github.com/leventtoramanli/seaofsea"}
    ]},
    components:[
      {id:"fe_flutter", name:"Flutter App", type:"frontend", repo_id:"repo_flutter", path:"lib/", language:"Dart"},
      {id:"be_php_v1", name:"PHP v1 API", type:"backend", repo_id:"repo_php", path:"v1/", language:"PHP"},
      {id:"db_mariadb", name:"MariaDB", type:"database", repo_id:"repo_php", path:"database/", language:"SQL"}
    ],
    code_units:[
      {id:"dart_main", component_id:"fe_flutter", path:"lib/main.dart", imports:[], depends_on_units:[], provides:["MmsApp"], notes:"Entry"},
      {id:"php_router", component_id:"be_php_v1", path:"v1/index.php", imports:[], depends_on_units:[], provides:["Router"], notes:"v1 router"},
      {id:"php_crud", component_id:"be_php_v1", path:"v1/crud.php", imports:[], depends_on_units:["php_auth"], provides:["Crud"], notes:"Central CRUD"},
      {id:"php_auth", component_id:"be_php_v1", path:"v1/core/AuthService.php", imports:[], depends_on_units:[], provides:["AuthService"], notes:"JWT/permissions"}
    ],
    interfaces:[
      {id:"if_generic", from_component:"fe_flutter", to_component:"be_php_v1", kind:"http_json",
       endpoint_or_function:"POST /v1", request_shape:{action:"string", resource:"string", payload:"object"},
       response_shape:{success:"bool", message:"string", data:"object"}, notes:"router → module → crud"}
    ],
    sql_contexts:[
      {id:"sql_companies", name:"companies", kind:"table", ddl_or_ref:"CREATE TABLE companies (...)"},
      {id:"sql_positions", name:"company_positions", kind:"table", ddl_or_ref:"CREATE TABLE company_positions (...)"}
    ],
    dependencies:[
      {from:"fe_flutter", to:"be_php_v1", type:"runtime", reason:"HTTP JSON via v1"},
      {from:"be_php_v1", to:"db_mariadb", type:"data", reason:"CRUD over SQL"}
    ],
    todos:[
      {id:"T-0100", title:"Auto-scan repos", description:"import/include grafiği çıkar", component_ids:["fe_flutter","be_php_v1"], labels:["infra"], priority:"P1", status:"todo", created_at:new Date().toISOString()},
      {id:"T-0101", title:"Extract SQL DDL", description:"DDL yakala ve sql_contexts doldur", component_ids:["db_mariadb"], labels:["db"], priority:"P1", status:"todo", created_at:new Date().toISOString()}
    ],
    meta:{version:1, updated_at:new Date().toISOString()}
  };

  const storageKey = "seaofsea_arch_map_v1";
  const state = loadState();

  function loadState(){
    try{ const raw = localStorage.getItem(storageKey); if(raw){ return JSON.parse(raw); } }catch(e){}
    return structuredClone(defaultState);
  }
  function saveState(){ state.meta.updated_at = new Date().toISOString(); localStorage.setItem(storageKey, JSON.stringify(state)); }

  // --- UI helpers -------------------------------------------------------------
  const tabs = [
    {id:"overview", name:"Genel"},
    {id:"components", name:"Bileşenler"},
    {id:"code", name:"Kod Birimleri"},
    {id:"interfaces", name:"Arayüzler"},
    {id:"sql", name:"SQL Bağlamları"},
    {id:"deps", name:"Bağımlılıklar"},
    {id:"todos", name:"ToDo"}
  ];
  let activeTab = localStorage.getItem("seaofsea_active_tab") || "overview";

  function renderTabs(){
    const el = document.getElementById("tabbar");
    el.innerHTML = "";
    tabs.forEach(t=>{
      const b = document.createElement("button");
      b.textContent = t.name;
      b.className = (t.id===activeTab?"tab-active":"");
      b.onclick = ()=>{ activeTab=t.id; localStorage.setItem("seaofsea_active_tab",activeTab); render(); };
      el.appendChild(b);
    });
  }

  function render(){
    renderTabs();
    const view = document.getElementById("view");
    saveState();
    if(activeTab==="overview") return renderOverview(view);
    if(activeTab==="components") return renderComponents(view);
    if(activeTab==="code") return renderCodeUnits(view);
    if(activeTab==="interfaces") return renderInterfaces(view);
    if(activeTab==="sql") return renderSql(view);
    if(activeTab==="deps") return renderDeps(view);
    if(activeTab==="todos") return renderTodos(view);
  }

  // --- Renderers --------------------------------------------------------------
  function renderOverview(root){
    root.innerHTML = `
      <div class="grid">
        <div class="card col-8">
          <h2>Proje</h2>
          <div><b>${escapeHtml(state.project.name)}</b></div>
          <div class="muted small">${escapeHtml(state.project.description||"")}</div>
          <details style="margin-top:8px"><summary>Repos</summary>
            <ul>${(state.project.repos||[]).map(r=>`<li><a class="btn" href="${r.url}" target="_blank">${escapeHtml(r.name)}</a></li>`).join("")}</ul>
          </details>
        </div>
        <div class="card col-4">
          <h2>Hızlı İşlemler</h2>
          <div class="row">
            <button onclick="addQuickTodo()">Yeni ToDo</button>
            <button onclick="quickComponent()">Yeni Bileşen</button>
          </div>
          <div class="foot">Veriler <span class="kbd">localStorage</span>'a kaydedilir.</div>
        </div>
      </div>
      <div class="grid" style="margin-top:12px">
        <div class="card col-6"><h2>Özet</h2>
          <div class="row">
            <span class="pill">Bileşen: ${(state.components||[]).length}</span>
            <span class="pill">Kod Birimi: ${(state.code_units||[]).length}</span>
            <span class="pill">Arayüz: ${(state.interfaces||[]).length}</span>
            <span class="pill">SQL: ${(state.sql_contexts||[]).length}</span>
            <span class="pill">Bağımlılık: ${(state.dependencies||[]).length}</span>
            <span class="pill">ToDo: ${(state.todos||[]).length}</span>
          </div>
        </div>
        <div class="card col-6"><h2>Son Güncelleme</h2>
          <div class="muted">${new Date(state.meta?.updated_at||Date.now()).toLocaleString()}</div>
        </div>
      </div>
    `;
  }

  function renderComponents(root){
    root.innerHTML = `
      <div class="card">
        <h2>Bileşenler</h2>
        <table><thead><tr><th>id</th><th>Ad</th><th>Tür</th><th>Dil</th><th>Yol</th><th></th></tr></thead>
        <tbody>
          ${(state.components||[]).map((c,i)=>`
            <tr>
              <td><span class="kbd">${escapeHtml(c.id)}</span></td>
              <td>${escapeHtml(c.name)}</td>
              <td>${escapeHtml(c.type||"")}</td>
              <td>${escapeHtml(c.language||"")}</td>
              <td>${escapeHtml(c.path||"")}</td>
              <td class="right"><button onclick="removeAt(state.components, ${i})">Sil</button></td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni Bileşen Ekle</summary>
          <div class="grid">
            <div class="col-6"><label>id</label><input id="cmp_id"></div>
            <div class="col-6"><label>Ad</label><input id="cmp_name"></div>
            <div class="col-4"><label>Tür</label><input id="cmp_type" placeholder="frontend/backend/database"></div>
            <div class="col-4"><label>Dil</label><input id="cmp_lang" placeholder="Dart/PHP/SQL"></div>
            <div class="col-4"><label>Yol</label><input id="cmp_path" placeholder="lib/ veya v1/"></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addComponent()">Ekle</button></div>
        </details>
      </div>`;
  }

  function renderCodeUnits(root){
    root.innerHTML = `
      <div class="card">
        <h2>Kod Birimleri</h2>
        <table><thead><tr><th>id</th><th>component</th><th>path</th><th>depends_on_units</th><th>provides</th><th></th></tr></thead>
        <tbody>
          ${(state.code_units||[]).map((u,i)=>`
            <tr>
              <td><span class="kbd">${escapeHtml(u.id)}</span></td>
              <td>${escapeHtml(u.component_id)}</td>
              <td>${escapeHtml(u.path)}</td>
              <td>${escapeHtml((u.depends_on_units||[]).join(", "))}</td>
              <td>${escapeHtml((u.provides||[]).join(", "))}</td>
              <td class="right"><button onclick="removeAt(state.code_units, ${i})">Sil</button></td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni Kod Birimi</summary>
          <div class="grid">
            <div class="col-4"><label>id</label><input id="cu_id"></div>
            <div class="col-4"><label>component_id</label><input id="cu_comp" placeholder="fe_flutter"></div>
            <div class="col-4"><label>path</label><input id="cu_path" placeholder="lib/…"></div>
            <div class="col-6"><label>depends_on_units (; ile)</label><input id="cu_dep"></div>
            <div class="col-6"><label>provides (; ile)</label><input id="cu_prov"></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addCodeUnit()">Ekle</button></div>
        </details>
      </div>`;
  }

  function renderInterfaces(root){
    root.innerHTML = `
      <div class="card">
        <h2>Arayüzler (FE↔BE)</h2>
        <table><thead><tr><th>id</th><th>from→to</th><th>kind</th><th>endpoint</th><th>not</th><th></th></tr></thead>
        <tbody>
          ${(state.interfaces||[]).map((it,i)=>`
            <tr>
              <td><span class="kbd">${escapeHtml(it.id)}</span></td>
              <td>${escapeHtml(it.from_component)} → ${escapeHtml(it.to_component)}</td>
              <td>${escapeHtml(it.kind||"")}</td>
              <td>${escapeHtml(it.endpoint_or_function||"")}</td>
              <td>${escapeHtml(it.notes||"")}</td>
              <td class="right"><button onclick="removeAt(state.interfaces, ${i})">Sil</button></td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni Arayüz</summary>
          <div class="grid">
            <div class="col-4"><label>id</label><input id="if_id"></div>
            <div class="col-4"><label>from_component</label><input id="if_from" placeholder="fe_flutter"></div>
            <div class="col-4"><label>to_component</label><input id="if_to" placeholder="be_php_v1"></div>
            <div class="col-4"><label>kind</label><input id="if_kind" placeholder="http_json"></div>
            <div class="col-8"><label>endpoint_or_function</label><input id="if_ep" placeholder="POST /v1"></div>
            <div class="col-12"><label>notes</label><input id="if_notes"></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addInterface()">Ekle</button></div>
        </details>
      </div>`;
  }

  function renderSql(root){
    root.innerHTML = `
      <div class="card">
        <h2>SQL Bağlamları</h2>
        <table><thead><tr><th>id</th><th>name</th><th>kind</th><th>DDL/ref</th><th></th></tr></thead>
        <tbody>
          ${(state.sql_contexts||[]).map((s,i)=>`
            <tr>
              <td><span class="kbd">${escapeHtml(s.id)}</span></td>
              <td>${escapeHtml(s.name)}</td>
              <td>${escapeHtml(s.kind||"")}</td>
              <td class="small">${escapeHtml((s.ddl_or_ref||"").slice(0,120))}…</td>
              <td class="right"><button onclick="removeAt(state.sql_contexts, ${i})">Sil</button></td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni SQL</summary>
          <div class="grid">
            <div class="col-4"><label>id</label><input id="sql_id"></div>
            <div class="col-4"><label>name</label><input id="sql_name"></div>
            <div class="col-4"><label>kind</label><input id="sql_kind" placeholder="table/view/query"></div>
            <div class="col-12"><label>DDL veya Referans</label><textarea id="sql_ddl" rows="4"></textarea></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addSql()">Ekle</button></div>
        </details>
      </div>`;
  }

  function renderDeps(root){
    root.innerHTML = `
      <div class="card">
        <h2>Bağımlılıklar</h2>
        <table><thead><tr><th>from</th><th>to</th><th>type</th><th>reason</th><th></th></tr></thead>
        <tbody>
          ${(state.dependencies||[]).map((d,i)=>`
            <tr>
              <td>${escapeHtml(d.from)}</td>
              <td>${escapeHtml(d.to)}</td>
              <td>${escapeHtml(d.type||"")}</td>
              <td>${escapeHtml(d.reason||"")}</td>
              <td class="right"><button onclick="removeAt(state.dependencies, ${i})">Sil</button></td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni Bağımlılık</summary>
          <div class="grid">
            <div class="col-4"><label>from</label><input id="dep_from" placeholder="fe_flutter"></div>
            <div class="col-4"><label>to</label><input id="dep_to" placeholder="be_php_v1"></div>
            <div class="col-4"><label>type</label><input id="dep_type" placeholder="runtime/data/build"></div>
            <div class="col-12"><label>reason</label><input id="dep_reason"></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addDep()">Ekle</button></div>
        </details>
      </div>`;
  }

  function renderTodos(root){
    root.innerHTML = `
      <div class="card">
        <h2>ToDo</h2>
        <table><thead><tr><th>id</th><th>başlık</th><th>etiketler</th><th>öncelik</th><th>durum</th><th></th></tr></thead>
        <tbody>
          ${(state.todos||[]).map((t,i)=>`
            <tr>
              <td><span class="kbd">${escapeHtml(t.id)}</span></td>
              <td title="${escapeHtml(t.description||"")}">${escapeHtml(t.title)}</td>
              <td>${escapeHtml((t.labels||[]).join(", "))}</td>
              <td>${escapeHtml(t.priority||"")}</td>
              <td>${escapeHtml(t.status||"")}</td>
              <td class="right">
                <button onclick="toggleTodo(${i})">Durum</button>
                <button onclick="removeAt(state.todos, ${i})">Sil</button>
              </td>
            </tr>`).join("")}
        </tbody></table>
        <details style="margin-top:8px"><summary>Yeni ToDo</summary>
          <div class="grid">
            <div class="col-4"><label>id</label><input id="td_id" placeholder="T-XXXX"></div>
            <div class="col-8"><label>başlık</label><input id="td_title"></div>
            <div class="col-12"><label>açıklama</label><input id="td_desc"></div>
            <div class="col-6"><label>component_ids (; ile)</label><input id="td_components" placeholder="fe_flutter;be_php_v1"></div>
            <div class="col-3"><label>öncelik</label><select id="td_pri"><option>P1</option><option selected>P2</option><option>P3</option></select></div>
            <div class="col-3"><label>durum</label><select id="td_status"><option>todo</option><option>in_progress</option><option>done</option></select></div>
          </div>
          <div class="right" style="margin-top:8px"><button onclick="addTodo()">Ekle</button></div>
        </details>
      </div>`;
  }

  // --- Actions ----------------------------------------------------------------
  function addComponent(){
    const c={id:gid("cmp_id").value.trim(),name:gid("cmp_name").value.trim(),type:gid("cmp_type").value.trim(),language:gid("cmp_lang").value.trim(),path:gid("cmp_path").value.trim()};
    if(!c.id||!c.name){return alert("id ve Ad gerekli")}
    state.components.push(c); render();
  }
  function addCodeUnit(){
    const u={id:gid("cu_id").value.trim(),component_id:gid("cu_comp").value.trim(),path:gid("cu_path").value.trim(),depends_on_units:splitList(gid("cu_dep").value),provides:splitList(gid("cu_prov").value)};
    if(!u.id||!u.component_id||!u.path){return alert("id, component_id, path gerekli")}
    state.code_units.push(u); render();
  }
  function addInterface(){
    const it={id:gid("if_id").value.trim(),from_component:gid("if_from").value.trim(),to_component:gid("if_to").value.trim(),kind:gid("if_kind").value.trim(),endpoint_or_function:gid("if_ep").value.trim(),notes:gid("if_notes").value.trim()};
    if(!it.id){return alert("id gerekli")}
    state.interfaces.push(it); render();
  }
  function addSql(){
    const s={id:gid("sql_id").value.trim(),name:gid("sql_name").value.trim(),kind:gid("sql_kind").value.trim(),ddl_or_ref:gid("sql_ddl").value};
    if(!s.id||!s.name){return alert("id ve name gerekli")}
    state.sql_contexts.push(s); render();
  }
  function addDep(){
    const d={from:gid("dep_from").value.trim(),to:gid("dep_to").value.trim(),type:gid("dep_type").value.trim(),reason:gid("dep_reason").value.trim()};
    if(!d.from||!d.to){return alert("from ve to gerekli")}
    state.dependencies.push(d); render();
  }
  function addTodo(){
    const t={id:gid("td_id").value.trim()||`T-${(Math.random()*1e6|0).toString().padStart(4,'0')}`,
      title:gid("td_title").value.trim(),description:gid("td_desc").value.trim(),component_ids:splitList(gid("td_components").value),labels:[],priority:gid("td_pri").value,status:gid("td_status").value,created_at:new Date().toISOString()};
    if(!t.title){return alert("başlık gerekli")}
    state.todos.push(t); render();
  }
  function toggleTodo(i){
    const order=["todo","in_progress","done"]; const cur=state.todos[i].status||"todo"; const nx=order[(order.indexOf(cur)+1)%order.length]; state.todos[i].status=nx; render();
  }
  function removeAt(arr,i){ arr.splice(i,1); render(); }

  function addQuickTodo(){
    const t = {id:`T-${(Math.random()*1e6|0)}`, title:"Yeni görev", description:"", component_ids:[], labels:[], priority:"P2", status:"todo", created_at:new Date().toISOString()};
    state.todos.push(t); activeTab="todos"; render();
  }
  function quickComponent(){
    const c={id:`cmp_${(Math.random()*1e5|0)}`, name:"Yeni Bileşen", type:"", language:"", path:""};
    state.components.push(c); activeTab="components"; render();
  }

  // --- Import / Export --------------------------------------------------------
  document.getElementById("importBtn").onclick = ()=>{
    const f = document.getElementById("fileInput").files?.[0];
    if(!f) return alert("Bir JSON dosyası seçin");
    const r = new FileReader();
    r.onload = ()=>{ try{ const obj = JSON.parse(r.result); Object.assign(state, obj); activeTab="overview"; render(); }catch(e){ alert("JSON okunamadı: "+e.message) } };
    r.readAsText(f);
  };
  document.getElementById("exportJsonBtn").onclick = ()=> downloadBlob(JSON.stringify(state,null,2), `architecture_map.${new Date().toISOString().slice(0,10)}.json`, "application/json");
  document.getElementById("exportTodosCsvBtn").onclick = ()=> downloadBlob(toCSV(state.todos||[], ["id","title","description","component_ids","labels","priority","status","created_at"], {arraysAsSemiColon:true}), "todos.csv", "text/csv");
  document.getElementById("exportDepsCsvBtn").onclick = ()=> downloadBlob(toCSV(state.dependencies||[], ["from","to","type","reason"], {}), "dependencies.csv", "text/csv");

  // --- Utils ------------------------------------------------------------------
  function gid(id){ return document.getElementById(id); }
  function splitList(s){ return s? s.split(";").map(x=>x.trim()).filter(Boolean):[] }
  function escapeHtml(s){ return (s||"").replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c])) }
  function downloadBlob(text, filename, type){ const blob = new Blob([text], {type}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href=url; a.download=filename; a.click(); URL.revokeObjectURL(url); }
  function toCSV(rows, cols, opts={}){
    const esc=v=>{ if(Array.isArray(v)){ return opts.arraysAsSemiColon? v.join(';'): JSON.stringify(v) } v=(v==null?"":String(v)); if(/[",\n]/.test(v)) v='"'+v.replace(/"/g,'""')+'"'; return v };
    const head=cols.join(',');
    const body=(rows||[]).map(r=> cols.map(c=> esc(r[c])).join(',')).join('\n');
    return head+'\n'+body;
  }

  // Kick
  render();
  </script>
</body>
</html>
