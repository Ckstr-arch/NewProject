import { escape } from '../utils/format.js';
let count = 0;
export function openModal({ title, content, onSubmit, submitLabel = 'Save' }) {
  const opener = document.activeElement;
  const dialog = document.createElement('dialog'); dialog.className = 'modal';
  const id = `modal-title-${++count}`; dialog.setAttribute('aria-labelledby', id);
  dialog.innerHTML = `<div class="modal-heading"><h2 id="${id}">${escape(title)}</h2><button class="icon-btn modal-close" type="button" aria-label="Close dialog">×</button></div><div class="modal-content">${content}</div>`;
  if (onSubmit) {
    const form = dialog.querySelector('form');
    if (form) form.addEventListener('submit', onSubmit);
  }
  dialog.querySelector('.modal-close').onclick = () => dialog.close();
  dialog.addEventListener('click', event => { if (event.target === dialog) { const bounds=dialog.getBoundingClientRect(); if(event.clientX<bounds.left || event.clientX>bounds.right || event.clientY<bounds.top || event.clientY>bounds.bottom) dialog.close(); } });
  dialog.addEventListener('close', () => { dialog.remove(); if(opener?.isConnected) opener.focus(); }, { once: true });
  document.body.append(dialog); dialog.showModal();
  return dialog;
}
export function confirmDialog(message) {
  return new Promise(resolve => {
    const dialog = openModal({ title: 'Please confirm', content: `<p>${escape(message)}</p><div class="form-actions"><button type="button" class="btn btn-secondary" data-cancel>Keep it</button><button type="button" class="btn btn-danger" data-confirm>Confirm</button></div>` });
    let confirmed = false;
    dialog.querySelector('[data-confirm]').onclick = () => { confirmed = true; dialog.close(); };
    dialog.querySelector('[data-cancel]').onclick = () => dialog.close();
    dialog.addEventListener('close', () => resolve(confirmed), { once: true });
    dialog.querySelector('[data-cancel]').focus();
  });
}
