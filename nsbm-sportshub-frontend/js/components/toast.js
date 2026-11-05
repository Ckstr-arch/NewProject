export function toast(message, type = 'success') {
  const region = document.getElementById('toasts');
  const element = document.createElement('div');
  element.className = `toast toast-${type}`;
  element.setAttribute('role', type === 'error' ? 'alert' : 'status');
  const text = document.createElement('span'); text.textContent = message;
  const close = document.createElement('button'); close.type = 'button'; close.className = 'icon-btn'; close.setAttribute('aria-label', 'Dismiss notification'); close.textContent = '×';
  close.addEventListener('click', () => element.remove());
  element.append(text, close); region.append(element);
  setTimeout(() => element.remove(), 6500);
}
