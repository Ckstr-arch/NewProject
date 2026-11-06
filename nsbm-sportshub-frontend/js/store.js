function preference(key, fallback) {
  try { return localStorage.getItem(key) || fallback; } catch { return fallback; }
}
let state = { user: null, permissions: [], route: '', theme: preference('sportshub-theme', 'light'), notifications: true, loading: false };
const listeners = new Set();
export const store = {
  get: () => ({ ...state }),
  set(patch) { state = { ...state, ...patch }; for (const listener of listeners) listener({ ...state }); },
  subscribe(listener) { listeners.add(listener); return () => listeners.delete(listener); },
  setTheme(theme) {
    theme = theme === 'dark' ? 'dark' : 'light';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('sportshub-theme', theme); } catch { /* Preference persistence is optional. */ }
    this.set({ theme });
  }
};
