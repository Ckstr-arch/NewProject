import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, field, bindForm, renderTable, renderPager, statusBadge } from '../components/table.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

const date=value=>new Date(value).toLocaleString('en-GB',{dateStyle:'medium',timeStyle:'short'});
const inputDate=value=>value?new Date(new Date(value).getTime()-new Date(value).getTimezoneOffset()*60000).toISOString().slice(0,16):'';
export async function render(container) {
  const admin=store.get().user?.role==='admin';
  let page=1,sequence=0,disposed=false,records=[],registrations=[];
  container.innerHTML=`<div class="page-heading"><div><p class="eyebrow">RISE TO THE OCCASION</p><h1>Tournaments</h1><p class="muted">Big games. Shared ambition. Find your next challenge.</p></div>${admin?'<button class="btn btn-primary" id="add-tournament">+ Create tournament</button>':''}</div><div class="toolbar"><label class="search-field"><span class="sr-only">Search tournaments</span><input type="search" id="tournament-search" placeholder="Search tournaments…"></label><label><span class="sr-only">Sport</span><select id="tournament-sport"><option value="">All sports</option></select></label><label><span class="sr-only">Sort tournaments</span><select id="tournament-sort"><option value="startsAt">Event date</option><option value="name">Name A–Z</option></select></label></div><div id="tournament-content" aria-live="polite"></div>`;
  const content=container.querySelector('#tournament-content'),search=container.querySelector('#tournament-search'),sport=container.querySelector('#tournament-sport'),sort=container.querySelector('#tournament-sort');
  async function load(){const version=++sequence;
  content.innerHTML='<div class="loading-state" role="status">Loading tournaments…</div>';
  try{const [response,mine]=await Promise.all([api.listTournaments({page,limit:6,search:search.value,sport:sport.value,sort:sort.value}),admin?Promise.resolve({data:[]}):api.myRegistrations({limit:100})]);
  if(disposed||version!==sequence)return;
  if(response.pagination.totalPages && page > response.pagination.totalPages){page=response.pagination.totalPages;return load();}
      records=response.data;
  registrations=mine.data;
      content.innerHTML=records.length?`<div class="cards tournament-grid">${records.map(t=>{const registered=registrations.some(r=>r.tournamentId===t.id),closed=new Date(t.registrationDeadline)<=new Date(),full=t.registrationCount>=t.capacity;return `<article class="card tournament-card"><div class="tournament-card-top"><span class="sport-chip">${escape(t.sport)}</span>${statusBadge(closed?'closed':full?'full':'open')}</div><div class="tournament-symbol" aria-hidden="true">✦</div><h2>${escape(t.name)}</h2><p class="muted">${escape(t.description)}</p><dl class="detail-list"><dt>When</dt><dd>${escape(date(t.startsAt))}</dd><dt>Where</dt><dd>${escape(t.location)}</dd><dt>Registration</dt><dd>${escape(t.registrationCount)} / ${escape(t.capacity)} places</dd></dl><p class="muted small">Register by ${escape(date(t.registrationDeadline))}</p><div class="card-actions"><button class="btn btn-secondary" data-details="${Number(t.id)}">Details & fixtures</button>${admin?`<button class="btn btn-quiet" data-edit="${Number(t.id)}">Edit</button><button class="btn btn-quiet danger-text" data-delete="${Number(t.id)}">Delete</button>`:registered?'<span class="badge badge-success">Registered</span>':`<button class="btn btn-primary" data-register="${Number(t.id)}" ${closed||full?'disabled':''}>${closed?'Registration closed':full?'Tournament full':'Register'}</button>`}</div></article>`;}).join('')}</div>${renderPager(response.pagination)}`:'<div class="empty-state"><h2>No tournaments found</h2><p>Try another search or sport.</p></div>';
    }catch(error){if(!disposed&&version===sequence)content.innerHTML=`<div class="error-state" role="alert">${escape(error.message)} <button class="btn btn-secondary" data-retry>Try again</button></div>`;
  }}
  function edit(t={}){const dialog=openModal({title:t.id?'Edit tournament':'Create tournament',content:`<form class="form-grid">${field('name','Tournament name',{value:t.name||''})}${field('sport','Sport',{value:t.sport||''})}${field('description','Description',{type:'textarea',value:t.description||''})}${field('location','Venue',{value:t.location||''})}${field('capacity','Registration capacity',{type:'number',min:1,max:1000,value:t.capacity||32})}${field('registrationDeadline','Registration deadline',{type:'datetime-local',value:inputDate(t.registrationDeadline)})}${field('startsAt','Starts at',{type:'datetime-local',value:inputDate(t.startsAt)})}${field('endsAt','Ends at',{type:'datetime-local',value:inputDate(t.endsAt)})}<div class="form-actions"><button type="submit" class="btn btn-primary">Save tournament</button></div></form>`});
  bindForm(dialog.querySelector('form'),data=>{if(new Date(data.endsAt)<=new Date(data.startsAt)){const e=new Error('End time must follow the start time.');
  e.errors={endsAt:['Choose an end time after the start.']};
  throw e;
  }if(new Date(data.registrationDeadline)>=new Date(data.startsAt)){const e=new Error('Registration must close before the tournament starts.');
  e.errors={registrationDeadline:['Choose a deadline before the start.']};
  throw e;
  }const body={...data,capacity:Number(data.capacity),startsAt:new Date(data.startsAt).toISOString(),endsAt:new Date(data.endsAt).toISOString(),registrationDeadline:new Date(data.registrationDeadline).toISOString()};
  return t.id?api.updateTournament(t.id,body):api.createTournament(body);
  },{success:'Tournament saved',onSuccess:()=>{dialog.close();
  load();
  }});
  }
  async function details(id){const [{data:t},fixtures]=await Promise.all([api.getTournament(id),api.listMatches({tournamentId:id,limit:100,sort:'startsAt'})]);
  if(disposed)return;
  openModal({title:t.name,content:`<p class="eyebrow">${escape(t.sport)}</p><p>${escape(t.description)}</p><p><strong>${escape(t.location)}</strong><br>${escape(date(t.startsAt))} — ${escape(date(t.endsAt))}</p><h3>Fixtures & results</h3>${renderTable([{label:'Fixture',render:m=>`${escape(m.homeTeam)} v ${escape(m.awayTeam)}`},{label:'Date',render:m=>escape(date(m.startsAt))},{label:'Result',render:m=>m.status==='completed'?`${escape(m.homeScore)} : ${escape(m.awayScore)}`:statusBadge('scheduled')}],fixtures.data,{empty:'Fixtures have not been announced yet.'})}<p><a class="btn btn-secondary" href="#/results" data-close-modal>View results & standings</a></p>`}).querySelector('[data-close-modal]')?.addEventListener('click',event=>event.target.closest('dialog').close());
  }
  let timer;
  search.addEventListener('input',()=>{clearTimeout(timer);
  timer=setTimeout(()=>{page=1;
  load();
  },250);
  });
  [sport,sort].forEach(el=>el.addEventListener('change',()=>{page=1;
  load();
  }));
  container.querySelector('#add-tournament')?.addEventListener('click',()=>edit());
  content.addEventListener('click',async event=>{const button=event.target.closest('button');
  if(!button)return;
  if(button.hasAttribute('data-retry'))return load();
  if(button.dataset.page){page=Number(button.dataset.page);
  return load();
  }const id=Number(button.dataset.details||button.dataset.edit||button.dataset.delete||button.dataset.register),t=records.find(x=>x.id===id);
  if(!t)return;
  if(button.dataset.edit)return edit(t);
  if(button.dataset.busy)return;
  button.dataset.busy='true';
  button.disabled=!(button.dataset.details||button.dataset.delete);
  try{if(button.dataset.details)await details(id);
  else if(button.dataset.delete){if(await confirmDialog(`Delete ${t.name}? Existing registrations or fixtures may prevent deletion.`)){await api.deleteTournament(id);
  toast('Tournament deleted');
  await load();
  }}else if(button.dataset.register){await api.registerTournament(id);
  toast('You are registered for the tournament');
  await load();
  }}catch(error){toast(error.message,'error');
  }finally{button.disabled=false;delete button.dataset.busy;
  }});
  try{const all=await api.listTournaments({limit:100});
  if(!disposed)sport.insertAdjacentHTML('beforeend',[...new Set(all.data.map(t=>t.sport))].sort().map(s=>`<option value="${escape(s)}">${escape(s)}</option>`).join(''));
  }catch{/* Main load shows errors. */}await load();
  return()=>{disposed=true;
  ++sequence;
  clearTimeout(timer);
  };
}
