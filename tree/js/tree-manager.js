// js/tree-manager.js

export let jsonData = {};
export let selectedFolderPath = 'lib'; // lib ilk açılışta hep açık
export let selectedItems = new Set();
export let openFolders = new Set(['lib']); // sadece lib açık başlar

import { showFileDetails, saveJsonToServer } from './details-manager.js';

export async function loadTree() {
  const response = await fetch('tree.json?' + new Date().getTime());
  jsonData = await response.json();
  console.log("Tree yüklendi:", jsonData);
  renderTree();
}


export function renderTree(container = document.getElementById('folderList'), obj = jsonData, path = []) {
  if (path.length === 0) container.innerHTML = '';

  for (const key in obj) {
    const item = obj[key];
    if (!item || !item.type) continue;
    const div = document.createElement('div');

    const handle = document.createElement('span');
    handle.textContent = '☰';
    handle.className = 'handle';
    div.appendChild(handle);

    const span = document.createElement('span');
    span.textContent = ' ' + key;
    div.appendChild(span);

    // Silme ikonu
    const deleteIcon = document.createElement('span');
    deleteIcon.textContent = ' 🗑️';
    deleteIcon.style.cursor = 'pointer';
    deleteIcon.style.float = 'right';
    deleteIcon.onclick = (e) => {
      e.stopPropagation();
      if (confirm(`${key} öğesini silmek istediğine emin misin?`)) {
        if (path.length === 0) {
          // Root öğeyi silme, engelle
          alert('Root öğeyi silemezsin!');
          return;
        }

        const pathToParent = [...path];
        let parent = jsonData;
        pathToParent.forEach(p => parent = parent[p]);

        if (parent && typeof parent === 'object') {
          delete parent[key];
        }

        renderTree(); // Güncelle
        saveJsonToServer(); // Kaydet
      }
    };


    div.appendChild(deleteIcon);

    div.style.marginLeft = path.length * 10 + 'px';
    div.dataset.path = [...path, key].join('/');

    div.addEventListener('click', (e) => {
      e.stopPropagation();
      highlightSelectedFolder(div);
      showFileDetails(div.dataset.path);
    });

    if (item.type === 'directory') {
      div.className = 'folder';
      div.addEventListener('dblclick', (e) => {
        e.stopPropagation();
        const subContainer = div.nextElementSibling;
        if (subContainer) {
          const isOpen = subContainer.style.display === 'block';
          subContainer.style.display = isOpen ? 'none' : 'block';
          if (isOpen) openFolders.delete(div.dataset.path);
          else openFolders.add(div.dataset.path);
        }
      });

      container.appendChild(div);

      const subContainer = document.createElement('div');
      subContainer.style.display = openFolders.has(div.dataset.path) ? 'block' : 'none';
      container.appendChild(subContainer);

      renderTree(subContainer, item, [...path, key]);
    } else if (item.type === 'file') {
      div.className = 'file-item';
      div.draggable = true;
      div.addEventListener('dragstart', dragStartHandler);
      container.appendChild(div);
    }
  }

  Sortable.create(container, {
    handle: '.handle',
    animation: 150,
    onEnd: () => {
      const parentPath = path.join('/');
      let parentObj = jsonData;
      if (parentPath) parentPath.split('/').forEach(p => parentObj = parentObj[p]);

      const newOrder = Array.from(container.children)
        .filter(el => el.dataset && el.dataset.path)
        .map(div => div.dataset.path.split('/').pop());

      // Yeni children'ları bu sıraya göre oluştur
      const newChildren = {};
      newOrder.forEach(key => {
        if (parentObj[key]) {
          newChildren[key] = parentObj[key];
        }
      });

      // Sadece alt öğeleri (file/folder) olan key'leri sil
      Object.keys(parentObj).forEach(k => {
        if (typeof parentObj[k] === 'object' && parentObj[k].type) {
          // Bu bir alt klasör ya da dosya: sil
          delete parentObj[k];
        }
      });

      // Yeni sıralamayı ekle (type gibi property'ler korunur)
      Object.assign(parentObj, newChildren);

      saveJsonToServer();
    }

  });
}


function highlightSelectedFolder(selectedDiv) {
  document.querySelectorAll('.folder').forEach(div => div.style.border = '');
  selectedDiv.style.border = '1px solid #00f';
  selectedFolderPath = selectedDiv.dataset.path; // !!! EKLENDİ !!!
}


function dragStartHandler(e) {
  e.dataTransfer.setData('text/plain', e.target.dataset.path);
}

document.getElementById('addFileBtn').addEventListener('click', () => {
  const newFileName = prompt('Yeni dosya adı:');
  if (!newFileName) return;

  let targetFolder = jsonData;
  selectedFolderPath.split('/').forEach(p => targetFolder = targetFolder[p]);

  if (targetFolder[newFileName]) {
    alert('Bu isimde bir dosya zaten var!');
    return;
  }

  targetFolder[newFileName] = { type: 'file' };

  renderTree();
  saveJsonToServer();
});

document.getElementById('addFolderBtn').addEventListener('click', () => {
  const newFolderName = prompt('Yeni klasör adı:');
  if (!newFolderName) return;

  let targetFolder = jsonData;
  selectedFolderPath.split('/').forEach(p => targetFolder = targetFolder[p]);

  if (targetFolder[newFolderName]) {
    alert('Bu isimde bir klasör zaten var!');
    return;
  }

  targetFolder[newFolderName] = { type: 'directory' };

  renderTree();
  saveJsonToServer();
});

loadTree();
