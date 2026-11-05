import { api } from '../services/api.js';
import { CONFIG } from '../config.js';
import { store } from '../store.js';
import { escape, field, bindForm } from '../components/table.js';
import { confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

/** Profile, local appearance and an honest API connectivity check. */
export async function render(container) {
  const lifetime = new AbortController();
  const { signal } = lifetime;
  const profile = await api.profile();
  const user = profile.data;
  container.innerHTML = `
    <div class="page-heading"><div><p class="eyebrow">Make it yours</p><h1>Settings</h1><p class="muted">Your profile, preferences and connection.</p></div></div>
    <div class="settings-grid cards">
      <section class="card"><h2>Personal details</h2><p class="muted">Your email and role are managed by the university.</p>
        <form id="profile-form" class="form-grid">
          ${field('name', 'Full name', { value: user.name, min: 2, max: 80 })}
          ${field('faculty', 'Faculty', { value: user.faculty || '', required: false, max: 100 })}
          <div class="field"><label for="profile-email">Email address</label><input id="profile-email" type="email" value="${escape(user.email)}" readonly></div>
          <div class="field"><label for="profile-student-id">Student ID / role</label><input id="profile-student-id" value="${escape(user.studentId || user.role)}" readonly></div>
          <label class="checkbox-label"><input type="checkbox" name="notifications" ${user.notifications ? 'checked' : ''}> Receive sports updates</label>
          <p class="muted">This saves your preference. Email delivery requires the separately developed backend.</p>
          <div class="form-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
        </form>
      </section>
      <div class="settings-stack">
        <section class="card"><h2>Appearance</h2><p class="muted">Choose a theme for this browser.</p>
          <div class="toolbar" role="group" aria-label="Color theme">
            <button type="button" class="btn btn-secondary" data-theme="light" aria-pressed="${store.get().theme === 'light'}">Light</button>
            <button type="button" class="btn btn-secondary" data-theme="dark" aria-pressed="${store.get().theme === 'dark'}">Dark</button>
          </div>
        </section>
        <section class="card"><h2>Backend connection</h2><p class="muted">${CONFIG.API_MODE === 'mock' ? 'Demo Mode uses this browser’s simulated records. A PHP/MySQL connection is not being tested.' : 'Live mode checks the configured PHP API. The backend must verify its database connection.'}</p>
          <div id="connection-result" role="status" aria-live="polite"><p class="muted">Checking connection…</p></div>
          <button id="check-connection" type="button" class="btn btn-secondary">Check again</button>
        </section>
        <section class="card"><h2>Session</h2><p class="muted">Signed in as ${escape(user.name)} · ${escape(user.role)}</p>
          <button type="button" id="settings-logout" class="btn btn-secondary">Sign out</button>
        </section>
        ${CONFIG.API_MODE === 'mock' ? `<section class="card"><h2>Demo workspace</h2><p class="muted">Reset all demo edits, applications, bookings and the simulated session in this browser. No real backend records are affected.</p><button type="button" id="reset-demo" class="btn btn-danger">Reset demo data</button></section>` : ''}
      </div>
    </div>`;

  bindForm(container.querySelector('#profile-form'), async (data) => {
    const result = await api.updateProfile({ name: data.name.trim(), faculty: data.faculty.trim() || null, notifications: data.notifications === 'on' });
    store.set({ user: result.data, notifications: result.data.notifications });
  }, { success: 'Profile updated.' });

  container.querySelectorAll('[data-theme]').forEach(button => button.addEventListener('click', () => {
    store.setTheme(button.dataset.theme);
    container.querySelectorAll('[data-theme]').forEach(choice => choice.setAttribute('aria-pressed', String(choice.dataset.theme === button.dataset.theme)));
  }, { signal }));

  const checkConnection = async () => {
    const button = container.querySelector('#check-connection');
    const result = container.querySelector('#connection-result');
    if (!button || signal.aborted) return;
    button.disabled = true;
    result.innerHTML = '<p class="muted">Checking connection…</p>';
    try {
      const response = await api.health();
      if (signal.aborted) return;
      const health = response.data;
      const healthy = health.status === 'ok';
      result.innerHTML = `<p class="${healthy ? 'connection-ok' : 'connection-error'}"><strong>${CONFIG.API_MODE === 'mock' ? 'Demo simulator available' : healthy ? 'Backend reports healthy' : 'Backend reports a problem'}</strong></p><p class="muted">Status: ${escape(health.status)}${health.database ? ` · Database: ${escape(health.database)}` : ''}</p>`;
    } catch (error) {
      if (!signal.aborted) result.innerHTML = `<p class="connection-error">Connection unavailable: ${escape(error.message || 'The server could not be reached.')}</p>`;
    } finally {
      if (!signal.aborted) button.disabled = false;
    }
  };
  container.querySelector('#check-connection').addEventListener('click', checkConnection, { signal });
  void checkConnection();

  container.querySelector('#settings-logout').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      await api.logout();
      store.set({ user: null });
      location.hash = '#/login';
    } catch (error) {
      toast(error.message || 'Could not sign out. Please try again.', 'error');
      button.disabled = false;
    }
  }, { signal });

  container.querySelector('#reset-demo')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    if (!await confirmDialog('Reset all saved demo data and sign out? This cannot be undone.')) return;
    button.disabled = true;
    try {
      await api.resetDemo();
      store.set({ user: null });
      location.hash = '#/login';
      location.reload();
    } catch (error) {
      toast(error.message || 'The demo data could not be reset.', 'error');
      button.disabled = false;
    }
  }, { signal });
  return () => lifetime.abort();
}
