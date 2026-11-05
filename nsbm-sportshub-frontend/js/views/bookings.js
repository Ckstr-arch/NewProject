import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, statusBadge, renderTable, renderPager, field, bindForm } from '../components/table.js';
import { formatDateTime, inputDate } from '../utils/format.js';
import { toast } from '../components/toast.js';
import { openModal, confirmDialog } from '../components/modal.js';

export async function render(container) {
  const admin = store.get().user.role === 'admin';
  const query = { page: 1, limit: 10, search: '', type: '', status: '', sort: 'startsAt', order: 'asc' };
  let request = 0;
  let disposed = false;
  let resources = [];
  let activeDialog = null;

  function requestBooking() {
    const start = new Date(); start.setDate(start.getDate() + 1); start.setHours(15, 0, 0, 0);
    const end = new Date(start.getTime() + 3600000);
    const dialog = openModal({ title: 'Request a booking', content: `<p class="muted">Reserve a facility or a piece of equipment. Requests need administrator approval. Maximum booking: four hours.</p><form class="form-grid" id="booking-request">
      ${field('facilityId', 'Facility or equipment', { options: [{ value: '', label: 'Select a resource' }, ...resources.map(resource => ({ value: resource.id, label: `${resource.name} · ${resource.type}` }))] })}
      ${field('purpose', 'Purpose', { type: 'textarea', min: 3, max: 300, placeholder: 'Tell us what you are planning.' })}
      ${field('startsAt', 'Start date and time', { type: 'datetime-local', value: inputDate(start), min: inputDate(new Date()) })}
      ${field('endsAt', 'End date and time', { type: 'datetime-local', value: inputDate(end), min: inputDate(new Date()) })}
      <p class="muted">Times use your device’s local timezone. Pending and approved requests reserve their time slot. Adjacent time slots are allowed.</p>
      <div class="form-actions"><button type="button" class="btn btn-secondary" data-cancel>Cancel</button><button type="submit" class="btn btn-primary">Send request</button></div>
    </form>` });
    activeDialog = dialog;
    dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
    bindForm(dialog.querySelector('form'), async data => {
      const errors = {};
      const startsAt = new Date(data.startsAt);
      const endsAt = new Date(data.endsAt);
      if (!Number.isFinite(startsAt.getTime()) || startsAt.getTime() <= Date.now()) errors.startsAt = ['Choose a future start time.'];
      if (!Number.isFinite(endsAt.getTime()) || endsAt <= startsAt) errors.endsAt = ['End time must be after the start time.'];
      else if (endsAt - startsAt > 4 * 3600000) errors.endsAt = ['A booking may last at most four hours.'];
      if (data.purpose.trim().length < 3) errors.purpose = ['Please describe the purpose in at least three characters.'];
      if (!resources.some(resource => Number(resource.id) === Number(data.facilityId))) errors.facilityId = ['Select a valid resource.'];
      if (Object.keys(errors).length) throw Object.assign(new Error('Please check the highlighted fields.'), { errors });
      return api.createBooking({ facilityId: Number(data.facilityId), purpose: data.purpose.trim(), startsAt: startsAt.toISOString(), endsAt: endsAt.toISOString() });
    }, { success: 'Booking request sent for approval.', onSuccess: async () => { dialog.close(); query.page = 1; await load(); } });
  }

  async function load() {
    const ticket = ++request;
    container.setAttribute('aria-busy', 'true');
    try {
      const [response, facilities] = await Promise.all([
        admin ? api.listBookings(query) : api.myBookings(query),
        api.listFacilities({ limit: 100 })
      ]);
      if (disposed || request !== ticket) return;
      resources = facilities.data;
      container.innerHTML = `<div class="page-heading"><div><p class="eyebrow">SPACE TO DO YOUR THING</p><h1>${admin ? 'Booking requests' : 'My bookings'}</h1><p class="muted">${admin ? 'Keep campus facilities working for everyone.' : 'Find a court, book your equipment, and get moving.'}</p></div>${!admin ? `<button type="button" id="new-booking" class="btn btn-primary" ${!resources.length ? 'disabled' : ''}>＋ Request a booking</button>` : ''}</div>
        <section class="card"><div class="section-heading"><div><h2>${admin ? 'Facility & equipment requests' : 'Your reservations'}</h2><p class="muted">Pending and approved bookings reserve the selected resource.</p></div><span class="badge">${escape(response.pagination?.total ?? response.data.length)} requests</span></div>
        <form id="booking-filter" class="toolbar" aria-label="Filter bookings"><label>Search<input type="search" name="search" value="${escape(query.search)}" placeholder="Search resource${admin ? ' or student' : ''}"></label><label>Type<select name="type"><option value="">All resources</option><option value="facility" ${query.type === 'facility' ? 'selected' : ''}>Facilities</option><option value="equipment" ${query.type === 'equipment' ? 'selected' : ''}>Equipment</option></select></label><label>Status<select name="status"><option value="">All statuses</option>${['pending', 'approved', 'rejected', 'cancelled'].map(status => `<option value="${status}" ${query.status === status ? 'selected' : ''}>${status[0].toUpperCase() + status.slice(1)}</option>`).join('')}</select></label><button class="btn btn-secondary" type="submit">Apply filters</button></form>
        ${renderTable([
          { label: 'Resource', render: row => `<strong>${escape(row.facilityName)}</strong><br>${statusBadge(row.type)}` },
          ...(admin ? [{ label: 'Student', key: 'userName' }] : []),
          { label: 'When', render: row => `${escape(formatDateTime(row.startsAt))}<br><span class="muted">to ${escape(formatDateTime(row.endsAt))}</span>` },
          { label: 'Purpose', key: 'purpose' },
          { label: 'Status', render: row => statusBadge(row.status) },
          { label: 'Actions', render: row => admin ? row.status === 'pending' ? `<div class="row-actions"><button class="btn btn-primary btn-small" data-id="${Number(row.id)}" data-status="approved">Approve</button><button class="btn btn-secondary btn-small" data-id="${Number(row.id)}" data-status="rejected">Reject</button></div>` : '<span class="muted">Reviewed</span>' : ['pending', 'approved'].includes(row.status) && new Date(row.startsAt) > new Date() ? `<button class="btn btn-secondary btn-small" data-id="${Number(row.id)}" data-status="cancelled">Cancel booking</button>` : '<span class="muted">—</span>' }
        ], response.data, { empty: query.search || query.status || query.type ? 'No requests match these filters.' : admin ? 'No booking requests to review.' : 'No bookings yet. Request a resource to get started.' })}
        ${renderPager(response.pagination)}</section>
        <section class="card"><div class="section-heading"><div><p class="eyebrow">ON CAMPUS</p><h2>Available resources</h2></div></div><div class="cards resource-grid">${resources.length ? resources.map(resource => `<article class="resource-card"><span aria-hidden="true">${resource.type === 'equipment' ? '◇' : '▦'}</span><h3>${escape(resource.name)}</h3><p class="muted">${escape(resource.location)}</p>${statusBadge(resource.type)}</article>`).join('') : '<p class="empty-state">No resources are available yet.</p>'}</div></section>`;
      container.querySelector('#new-booking')?.addEventListener('click', requestBooking);
      container.querySelector('#booking-filter').addEventListener('submit', event => {
        event.preventDefault(); const values = new FormData(event.currentTarget);
        query.search = values.get('search').trim(); query.status = values.get('status'); query.type = values.get('type'); query.page = 1;
        load();
      });
      container.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => { query.page = Number(button.dataset.page); load(); }));
      container.querySelectorAll('[data-status]').forEach(button => button.addEventListener('click', async () => {
        const status = button.dataset.status;
        const action = { approved: 'Approve', rejected: 'Reject', cancelled: 'Cancel' }[status];
        if (!await confirmDialog(`${action} this booking${status === 'cancelled' ? '? This releases its reserved time.' : ' request?'}`)) return;
        button.disabled = true;
        try { await api.setBookingStatus(Number(button.dataset.id), { status }); toast(`Booking ${status}.`); await load(); }
        catch (error) { toast(error.message || 'Could not update the booking.', 'error'); button.disabled = false; }
      }));
    } catch (error) {
      if (!disposed && ticket === request) {
        container.innerHTML = `<div class="page-heading"><h1>Bookings</h1></div><section class="card empty-state" role="alert"><h2>Unable to load bookings</h2><p>${escape(error.message)}</p><button class="btn btn-secondary" id="retry-bookings">Try again</button></section>`;
        container.querySelector('#retry-bookings').addEventListener('click', load);
      }
    } finally { if (!disposed && ticket === request) container.removeAttribute('aria-busy'); }
  }
  await load();
  return () => { disposed = true; request++; activeDialog?.close(); };
}
