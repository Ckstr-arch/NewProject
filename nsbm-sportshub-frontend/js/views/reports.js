import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, renderTable, renderPager } from '../components/table.js';

const reports = {
  memberships: {
    label: 'Memberships', method: 'membershipReport', description: 'Membership decisions by sports club.',
    columns: [{ label: 'Club', key: 'clubName' }, { label: 'Pending', key: 'pending' }, { label: 'Approved', key: 'approved' }, { label: 'Rejected', key: 'rejected' }]
  },
  participation: {
    label: 'Participation', method: 'participationReport', description: 'Student participation by sport.',
    columns: [{ label: 'Sport', key: 'sport' }, { label: 'Attended', key: 'attended' }, { label: 'Registered', key: 'registered' }]
  },
  tournaments: {
    label: 'Tournaments', method: 'tournamentReport', description: 'Tournament registrations and available capacity.',
    columns: [{ label: 'Tournament', key: 'name' }, { label: 'Registrations', key: 'registrations' }, { label: 'Capacity', key: 'capacity' }, { label: 'Places remaining', render: row => escape(Math.max(0, Number(row.capacity) - Number(row.registrations))) }]
  },
  bookings: {
    label: 'Bookings', method: 'bookingReport', description: 'Booking decisions by facility or equipment resource.',
    columns: [{ label: 'Resource', key: 'facilityName' }, { label: 'Pending', key: 'pending' }, { label: 'Approved', key: 'approved' }, { label: 'Rejected', key: 'rejected' }, { label: 'Cancelled', key: 'cancelled' }]
  }
};

/** Reports always come from the configured adapter and refresh on every tab change. */
export async function render(container) {
  if (store.get().user?.role !== 'admin') {
    container.innerHTML = '<section class="card empty-state"><h1>Access denied</h1><p>Reports are available to administrators.</p><a class="btn btn-primary" href="#/dashboard">Back to dashboard</a></section>';
    return;
  }
  const lifetime = new AbortController();
  let selected = 'memberships';
  let page = 1;
  let request = 0;
  container.innerHTML = `<div class="page-heading"><div><p class="eyebrow">Understand your campus</p><h1>Reports</h1><p class="muted">A clear view of club activity and sports operations.</p></div></div>
    <section class="card"><div class="tabs" role="tablist" aria-label="Report category">${Object.entries(reports).map(([key, report]) => `<button type="button" class="btn btn-secondary" role="tab" id="tab-${key}" data-report="${key}" aria-controls="report-panel" aria-selected="${key === selected}">${report.label}</button>`).join('')}</div>
      <div id="report-panel" role="tabpanel" aria-labelledby="tab-memberships" tabindex="0"><div id="report-content" aria-live="polite"></div></div>
    </section>`;
  const content = container.querySelector('#report-content');
  const load = async () => {
    const current = ++request;
    const report = reports[selected];
    container.querySelector('#report-panel').setAttribute('aria-labelledby', `tab-${selected}`);
    container.querySelectorAll('[data-report]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.report === selected)));
    content.innerHTML = '<p class="muted" role="status">Loading report…</p>';
    try {
      const response = await api[report.method]({ page, limit: 10 });
      if (current !== request || lifetime.signal.aborted) return;
      content.innerHTML = `<div class="page-heading"><div><h2>${report.label} overview</h2><p class="muted">${report.description}</p></div><span class="badge">${escape(response.pagination?.total ?? response.data.length)} records</span></div>${renderTable(report.columns, response.data, { empty: 'There are no records for this report yet.' })}${response.pagination ? renderPager(response.pagination) : ''}`;
    } catch (error) {
      if (current !== request || lifetime.signal.aborted) return;
      content.innerHTML = `<div class="empty-state"><h2>Report unavailable</h2><p>${escape(error.message || 'The report could not be loaded.')}</p><button type="button" class="btn btn-secondary" data-retry>Try again</button></div>`;
    }
  };
  container.addEventListener('click', event => {
    const tab = event.target.closest('[data-report]');
    if (tab) {
      selected = tab.dataset.report;
      page = 1;
      void load();
    }
    const next = event.target.closest('[data-page]');
    if (next && !next.disabled) {
      page = Number(next.dataset.page);
      void load();
    }
    if (event.target.closest('[data-retry]')) void load();
  }, { signal: lifetime.signal });
  container.querySelector('[role="tablist"]').addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const tabs = [...container.querySelectorAll('[data-report]')];
    let index = tabs.indexOf(document.activeElement);
    index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
    tabs[index].focus();
    tabs[index].click();
  }, { signal: lifetime.signal });
  await load();
  return () => lifetime.abort();
}
