// js/details-manager.js
import { jsonData, renderTree } from './tree-manager.js';
import { formatJsonView, enableEditing } from './json-editor.js';

export function showFileDetails(path) {
  const pathParts = path.split('/');
  let target = jsonData;
  pathParts.forEach(p => target = target[p]);

  const detailsDiv = document.getElementById('jsonView');
  detailsDiv.innerHTML = formatJsonView(target); // Yeni, kullanıcı dostu JSON görünümü

  const editBtn = document.getElementById('editJsonBtn');
  editBtn.style.display = 'block';
  editBtn.onclick = () => openJsonEditor(target, path);
}


function openJsonEditor(target, path) {
  const container = document.getElementById('jsonView');
  container.innerHTML = `
    <textarea id="jsonTextarea" style="width:100%; height: 400px;">${JSON.stringify(target, null, 2)}</textarea>
    <button id="saveJsonBtn">Kaydet</button>
  `;

  document.getElementById('saveJsonBtn').onclick = () => {
    try {
      const updated = JSON.parse(document.getElementById('jsonTextarea').value);
      let parent = jsonData;
      const keys = path.split('/');
      const lastKey = keys.pop();
      keys.forEach(k => parent = parent[k]);

      parent[lastKey] = updated;
      saveJsonToServer();
      renderTree();
      container.innerHTML = '';
      alert('Değişiklikler kaydedildi!');
    } catch (e) {
      alert('Hatalı JSON: ' + e.message);
    }
  };
}

export function saveJsonToServer() {
  fetch('update_json.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(jsonData)
  })
    .then(response => response.text())
    .then(result => console.log('JSON güncellendi: ' + result))
    .catch(error => {
      console.error('Hata:', error);
      alert('Hata oluştu!');
    });
}
