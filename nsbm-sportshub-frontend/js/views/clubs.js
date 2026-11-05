import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, field, bindForm, renderPager, statusBadge } from '../components/table.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

export async function render(container) {
  const admin = store.get().user?.role === 'admin';
  let page = 1, sequence = 0, disposed = false, members = [], clubs = [];
  container.innerHTML = `<div class="page-heading"><div><p class="eyebrow">FIND YOUR COMMUNITY</p><h1>Sports clubs</h1><p class="muted">A place for every passion. Discover your team and make your next move.</p></div>${admin ? '<button class="btn btn-primary" id="add-club">+ Create club</button>' : ''}</div>
    <div class="toolbar"><label class="search-field"><span class="sr-only">Search clubs</span><input type="search" id="club-search" placeholder="Search clubs, coaches, or locations…"></label><label><span class="sr-only">Filter by sport</span><select id="club-sport"><option value="">All sports</option></select></label><label><span class="sr-only">Sort clubs</span><select id="club-sort"><option value="name">Name A–Z</option><option value="capacity">Capacity</option></select></label><span class="muted" id="club-count"></span></div><div id="club-content" aria-live="polite"></div>`;
  const content = container.querySelector('#club-content');
  const search = container.querySelector('#club-search'), sport = container.querySelector('#club-sport'), sort = container.querySelector('#club-sort');
  async function load() {
    const version = ++sequence;
    content.innerHTML = '<div class="loading-state" role="status">Finding your next team…</div>';
    try {
      const [response, memberships] = await Promise.all([api.listClubs({page, limit: 6, search: search.value, sport: sport.value, sort: sort.value}), admin ? Promise.resolve({data: []}) : api.myMemberships({limit: 100})]);
      if (disposed || version !== sequence) return;
      if(response.pagination.totalPages && page > response.pagination.totalPages){page=response.pagination.totalPages;return load();}
      clubs = response.data; members = memberships.data;
      container.querySelector('#club-count').textContent = `${response.pagination.total} clubs`;
      content.innerHTML = clubs.length ? `<div class="cards club-grid">${clubs.map((club, i) => {
        const membership = members.find(m => m.clubId === club.id && ['pending', 'approved'].includes(m.status));
        return `<article class="card club-card"><div class="club-art sport-art-${i % 6}"><span class="sport-monogram" aria-hidden="true">${escape(club.sport.slice(0, 2).toUpperCase())}</span><span class="sport-chip">${escape(club.sport)}</span></div><div class="club-card-body"><h2>${escape(club.name)}</h2><p class="muted club-description">${escape(club.description)}</p><div class="club-meta"><span>⌖ ${escape(club.location)}</span><span>Up to ${escape(club.capacity)} members</span></div><div class="card-actions"><button class="btn btn-secondary" data-details="${Number(club.id)}">View club <span aria-hidden="true">→</span></button>${admin ? `<button class="btn btn-quiet" data-edit="${Number(club.id)}" aria-label="Edit ${escape(club.name)}">Edit</button><button class="btn btn-quiet danger-text" data-delete="${Number(club.id)}" aria-label="Delete ${escape(club.name)}">Delete</button>` : membership ? statusBadge(membership.status) : `<button class="btn btn-primary" data-join="${Number(club.id)}">Join club</button>`}</div></div></article>`;
      }).join('')}</div>${renderPager(response.pagination)}` : '<div class="empty-state"><h2>No clubs found</h2><p>Try another search or sport filter.</p></div>';
    } catch (error) { if (!disposed && version === sequence) content.innerHTML = `<div class="error-state" role="alert">${escape(error.message)} <button class="btn btn-secondary" data-retry>Try again</button></div>`; }
  }
  async function edit(club = {}) {
    const dialog = openModal({title: club.id ? 'Edit sports club' : 'Create sports club', content: `<form class="form-grid">${field('name', 'Club name', {value: club.name || ''})}${field('sport', 'Sport', {value: club.sport || ''})}${field('description', 'About the club', {type:'textarea', value:club.description || ''})}${field('coach', 'Coach', {value:club.coach || ''})}${field('location', 'Location', {value:club.location || ''})}${field('capacity', 'Member capacity', {type:'number',min:1,max:1000,value:club.capacity || 30})}<div class="form-actions"><button class="btn btn-primary" type="submit">${club.id ? 'Save changes' : 'Create club'}</button></div></form>`});
    bindForm(dialog.querySelector('form'), data => club.id ? api.updateClub(club.id, {...data, capacity:Number(data.capacity)}) : api.createClub({...data, capacity:Number(data.capacity)}), {success:'Club saved', onSuccess:() => {dialog.close();load();}});
  }
  let searchTimer;
  search.addEventListener('input', () => {clearTimeout(searchTimer);searchTimer=setTimeout(() => {page=1;load();}, 250);});
  sport.addEventListener('change', () => {page=1;load();}); sort.addEventListener('change', () => {page=1;load();});
  container.querySelector('#add-club')?.addEventListener('click', () => edit());
  content.addEventListener('click', async event => {
    const button = event.target.closest('button'); if (!button) return;
    if (button.hasAttribute('data-retry')) return load();
    if (button.dataset.page) {page=Number(button.dataset.page);return load();}
    const id = Number(button.dataset.details || button.dataset.edit || button.dataset.delete || button.dataset.join);
    const club=clubs.find(c => c.id === id); if(!club) return;
    if(button.dataset.edit) return edit(club);
    if (button.dataset.busy) return;
    button.dataset.busy = 'true';
    button.disabled = !(button.dataset.details || button.dataset.delete);
    try {
      if(button.dataset.details) {
        const {data} = await api.getClub(id);
        if(disposed) return;
        openModal({title:data.name,content:`<p class="eyebrow">${escape(data.sport)}</p><p>${escape(data.description)}</p><dl class="detail-list"><dt>Coach</dt><dd>${escape(data.coach)}</dd><dt>Location</dt><dd>${escape(data.location)}</dd><dt>Capacity</dt><dd>${escape(data.capacity)} members</dd></dl>${admin ? '' : '<p class="muted">Use Join club on the club card to request membership. Track approval in Memberships.</p>'}`});
      } else if(button.dataset.delete) {
        if(await confirmDialog(`Delete ${club.name}? Clubs with related records may be protected by the API.`)) {await api.deleteClub(id);toast('Club deleted');await load();}
      } else if(button.dataset.join) {await api.applyMembership({clubId:id});toast('Membership request sent');await load();}
    } catch(error) {toast(error.message,'error');} finally {button.disabled=false;delete button.dataset.busy;}
  });
  try {const all=await api.listClubs({limit:100}); if(!disposed) sport.insertAdjacentHTML('beforeend',[...new Set(all.data.map(c=>c.sport))].sort().map(s=>`<option value="${escape(s)}">${escape(s)}</option>`).join(''));} catch { /* Main load displays the API error with retry. */ }
  await load();
  return () => {disposed=true;++sequence;clearTimeout(searchTimer);};
}
