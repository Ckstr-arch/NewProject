import { escape } from '../utils/format.js';
import { toast } from './toast.js';
export { escape };
export function statusBadge(status) {
  const name = String(status ?? 'unknown');
  const allowed = ['pending','approved','rejected','cancelled','completed','scheduled','attended','registered','training','match','event','facility','equipment','open','full','closed','active','inactive','live','registration_open','upcoming','ongoing','confirmed'];
  return `<span class="badge badge-${allowed.includes(name) ? name : 'neutral'}">${escape(name)}</span>`;
}
export function renderTable(columns, rows, { empty = 'No records to show yet.' } = {}) {
  if (!rows.length) return `<div class="empty-state"><span aria-hidden="true">◇</span><h3>Nothing here yet</h3><p>${escape(empty)}</p></div>`;
  return `<div class="table-scroll" role="region" aria-label="Data table" tabindex="0"><table><thead><tr>${columns.map(column => `<th scope="col">${escape(column.label)}</th>`).join('')}</tr></thead><tbody>${rows.map(row => `<tr>${columns.map(column => `<td>${column.render ? column.render(row) : escape(row[column.key])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}
export function renderPager(pagination) {
  if (!pagination || pagination.totalPages <= 1) return '';
  return `<nav class="pagination" aria-label="Pagination"><p class="muted">Page ${escape(pagination.page)} of ${escape(pagination.totalPages)} <span>· ${escape(pagination.total)} records</span></p><div><button class="btn btn-secondary btn-small" data-page="${pagination.page-1}" ${pagination.page <= 1 ? 'disabled' : ''}>Previous</button><button class="btn btn-secondary btn-small" data-page="${pagination.page+1}" ${pagination.page >= pagination.totalPages ? 'disabled' : ''}>Next</button></div></nav>`;
}
let fieldCount = 0;
export function field(name, label, { type = 'text', value = '', required = true, min, max, options, step, placeholder = '' } = {}) {
  const id = `field-${name}-${++fieldCount}`;
  const textType = ['text','textarea','email','password','search'].includes(type);
  const attributes = `id="${id}" name="${escape(name)}" ${required ? 'required' : ''} ${min !== undefined ? `${textType ? 'minlength' : 'min'}="${escape(min)}"` : ''} ${max !== undefined ? `${textType ? 'maxlength' : 'max'}="${escape(max)}"` : ''} ${step !== undefined ? `step="${escape(step)}"` : ''} aria-describedby="${id}-error"`;
  const control = options ? `<select ${attributes}>${options.map(option => `<option value="${escape(option.value)}" ${String(option.value) === String(value) ? 'selected' : ''}>${escape(option.label)}</option>`).join('')}</select>`
    : type === 'textarea' ? `<textarea ${attributes} rows="3" placeholder="${escape(placeholder)}">${escape(value)}</textarea>`
    : `<input ${attributes} type="${escape(type)}" value="${escape(value)}" placeholder="${escape(placeholder)}">`;
  return `<div class="field"><label for="${id}">${escape(label)}${required ? '' : ' <span class="muted">(optional)</span>'}</label>${control}<span id="${id}-error" class="field-error" data-error-for="${escape(name)}"></span></div>`;
}
export function bindForm(form, handler, { success = 'Saved successfully.', pending = 'Saving…', onSuccess } = {}) {
  let busy = false;
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return;
    form.querySelectorAll('.field-error').forEach(element => { element.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach(element => element.removeAttribute('aria-invalid'));
    let summary = form.querySelector('.form-error');
    if (!summary) { summary=document.createElement('p'); summary.className='form-error'; summary.setAttribute('role','alert'); form.append(summary); }
    summary.textContent = '';
    if (!form.reportValidity()) return;
    const buttons = [...form.querySelectorAll('button[type="submit"],input[type="submit"]')];
    const labels = new Map(buttons.map(button => [button, button.innerHTML]));
    busy=true; buttons.forEach(button => { button.disabled=true; button.textContent=pending; });
    try {
      const data = Object.fromEntries(new FormData(form));
      const result = await handler(data);
      if (success) toast(success);
      if (onSuccess) await onSuccess(result);
    } catch (error) {
      summary.textContent=error.message || 'Could not save. Please try again.';
      for (const [name, messages] of Object.entries(error.errors || {})) {
        const input = form.elements.namedItem(name);
        const target = [...form.querySelectorAll('[data-error-for]')].find(element => element.dataset.errorFor===name);
        if (target) target.textContent=Array.isArray(messages) ? messages.join(' ') : String(messages);
        if (input?.setAttribute) input.setAttribute('aria-invalid','true');
      }
      form.querySelector('[aria-invalid="true"]')?.focus();
    } finally {
      busy=false; buttons.forEach(button => { button.disabled=false; button.innerHTML=labels.get(button); });
    }
  });
}
