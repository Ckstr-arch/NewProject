import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, statusBadge, renderTable, renderPager } from '../components/table.js';
import { toast } from '../components/toast.js';
import { confirmDialog } from '../components/modal.js';

const date = value => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));

export async function render(container) {
  const admin = store.get().user.role === 'admin';
  const query = { page: 1, limit: 10, status: '', search: '', sort: 'createdAt', order: 'desc' };
  let request = 0;
  let disposed = false;
  async function load() {
    const ticket = ++request;
    container.setAttribute('aria-busy', 'true');
    try {
      const [response, history] = await Promise.all([
        admin ? api.listMemberships(query) : api.myMemberships(query),
        admin ? Promise.resolve({ data: [] }) : api.participation({ limit: 100 })
      ]);
      if (disposed || request !== ticket) return;
      container.innerHTML = `<div class="page-heading"><div><p class="eyebrow">FIND YOUR PEOPLE</p><h1>${admin ? 'Membership requests' : 'My memberships'}</h1><p class="muted">${admin ? 'Help students take their first step into a team.' : 'A place for every interest. A team for every student.'}</p></div>${!admin ? '<a class="btn btn-primary" href="#/clubs">Explore clubs <span aria-hidden="true">→</span></a>' : ''}</div>
        <section class="card"><form class="toolbar" id="membership-filter" aria-label="Filter memberships"><label>Search<input name="search" type="search" value="${escape(query.search)}" placeholder="Search clubs${admin ? ' or students' : ''}"></label><label>Status<select name="status"><option value="">All statuses</option>${['pending', 'approved', 'rejected'].map(status => `<option value="${status}" ${query.status === status ? 'selected' : ''}>${status[0].toUpperCase() + status.slice(1)}</option>`).join('')}</select></label><button class="btn btn-secondary" type="submit">Apply filters</button></form>
        ${renderTable([
          { label: 'Club', key: 'clubName' },
          ...(admin ? [{ label: 'Student', key: 'userName' }] : []),
          { label: 'Requested', render: row => escape(date(row.createdAt)) },
          { label: 'Status', render: row => statusBadge(row.status) },
          ...(admin ? [{ label: 'Review', render: row => row.status === 'pending' ? `<div class="row-actions"><button class="btn btn-primary btn-sm" data-id="${Number(row.id)}" data-status="approved" aria-label="Approve ${escape(row.userName)} for ${escape(row.clubName)}">Approve</button><button class="btn btn-secondary btn-sm" data-id="${Number(row.id)}" data-status="rejected" aria-label="Reject ${escape(row.userName)} for ${escape(row.clubName)}">Reject</button></div>` : '<span class="muted">Reviewed</span>' }] : [])
        ], response.data, { empty: query.status || query.search ? 'No memberships match these filters.' : admin ? 'No membership requests yet.' : 'You have not applied to a club yet. Explore clubs to find your team.' })}
        ${renderPager(response.pagination)}</section>
        ${!admin ? `<section class="card"><div class="section-heading"><div><p class="eyebrow">YOUR SPORTING JOURNEY</p><h2>Participation history</h2></div></div>${renderTable([{ label: 'Event', key: 'event' }, { label: 'Sport', key: 'sport' }, { label: 'Date', render: row => escape(date(row.date)) }, { label: 'Status', render: row => statusBadge(row.status) }], history.data, { empty: 'No participation recorded yet.' })}</section>` : ''}`;
      container.querySelector('#membership-filter').addEventListener('submit', event => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        query.search = data.get('search').trim();
        query.status = data.get('status');
        query.page = 1;
        load();
      });
      container.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => {
        query.page = Number(button.dataset.page);
        load();
      }));
      container.querySelectorAll('[data-status]').forEach(button => button.addEventListener('click', async () => {
        const approved = button.dataset.status === 'approved';
        if (!await confirmDialog(`${approved ? 'Approve' : 'Reject'} this membership request?`)) return;
        button.disabled = true;
        try {
          await api.setMembershipStatus(Number(button.dataset.id), { status: button.dataset.status });
          toast(`Membership ${approved ? 'approved' : 'rejected'}.`);
          await load();
        } catch (error) {
          toast(error.message || 'Could not review the request.', 'error');
          button.disabled = false;
        }
      }));
    } catch (error) {
      if (!disposed && ticket === request) {
        container.innerHTML = `<div class="page-heading"><h1>Memberships</h1></div><section class="card empty-state" role="alert"><h2>Unable to load memberships</h2><p>${escape(error.message)}</p><button class="btn btn-secondary" id="retry-memberships">Try again</button></section>`;
        container.querySelector('#retry-memberships').addEventListener('click', load);
      }
    } finally {
      if (!disposed && request === ticket) container.removeAttribute('aria-busy');
    }
  }
  await load();
  return () => { disposed = true; request++; };
}
