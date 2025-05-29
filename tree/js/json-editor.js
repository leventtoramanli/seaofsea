// js/json-editor.js
import { jsonData, renderTree } from './tree-manager.js';
import { saveJsonToServer } from './details-manager.js';

export function formatJsonEditable(obj, path = []) {
  let html = '';
  if (typeof obj !== 'object' || obj === null) return '';

  for (const key in obj) {
    if (!obj.hasOwnProperty(key)) continue;
    const value = obj[key];
    const currentPath = [...path, key].join('/');

    if (typeof value === 'object' && value !== null) {
      html += `<div style="margin-left: ${path.length * 20}px;">
        <strong>${key}:</strong>
      </div>`;
      html += formatJsonEditable(value, [...path, key]);
    } else {
      html += `<div style="margin-left: ${path.length * 20}px;">
        <strong>${key}:</strong> <span>${value}</span>
      </div>`;
    }
  }

  return html;
}

export function formatJsonView(obj, depth = 0) {
    let html = '';
    const indent = depth * 20;
  
    for (const key in obj) {
      if (!obj.hasOwnProperty(key)) continue;
      const value = obj[key];
  
      // "tip", "tips", "warning", "danger" için özel renkler
      const highlightKeys = ['tip', 'tips', 'warning', 'danger'];
      const isHighlight = highlightKeys.includes(key.toLowerCase());
      const keyColor = isHighlight ? '#d00' : '#008'; // kırmızı vurgulu
      const valueColor = isHighlight ? '#d00' : '#080';
  
      if (typeof value === 'object' && value !== null) {
        html += `<div style="
          margin-left: ${indent}px; 
          font-weight: bold; 
          color: #005; 
          border-bottom: 1px solid #ddd; 
          padding: 4px 0;
          font-family: Consolas, monospace;">
            ${key} {</div>`;
        html += formatJsonView(value, depth + 1);
        html += `<div style="
          margin-left: ${indent}px; 
          font-weight: bold; 
          color: #005; 
          border-bottom: 1px solid #ddd; 
          padding: 4px 0;
          font-family: Consolas, monospace;">}</div>`;
      } else {
        html += `<div style="
          margin-left: ${indent}px; 
          color: #333; 
          border-bottom: 1px solid #ddd; 
          padding: 4px 0;
          font-family: Consolas, monospace;">
            <span style="color: ${keyColor};">"${key}":</span> 
            <span style="color: ${valueColor};">"${value}"</span>
          </div>`;
      }
    }
    return html;
  }
  

export function enableEditing() {
  document.querySelectorAll('.json-value').forEach(span => {
    span.addEventListener('blur', (e) => {
      const newValue = e.target.textContent;
      const p = e.target.dataset.path.split('/');
      const k = p.pop();
      let target = jsonData;
      p.forEach(seg => target = target[seg]);
      target[k] = newValue;
      saveJsonToServer();
    });
  });
}
