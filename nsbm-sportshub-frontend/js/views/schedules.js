import { api } from '../services/api.js';
import { store } from '../store.js';
import { escape, field, bindForm, renderTable, renderPager, statusBadge } from '../components/table.js';
import { openModal, confirmDialog } from '../components/modal.js';
import { toast } from '../components/toast.js';

const date = value => new Date(value).toLocaleString('en-GB', {dateStyle:'medium',timeStyle:'short'});
const inputDate = value => value ? new Date(new Date(value).getTime() - new Date(value).getTimezoneOffset()*60000).toISOString().slice(0,16) : '';
export async function render(container) {
  const admin=store.get().user?.role==='admin';
   let page=1, sequence=0,disposed=false,records=[],clubs=[];
  container.innerHTML=`<div class="page-heading"><div><p class="eyebrow">SHOW UP. LEVEL UP.</p><h1>Training & schedules</h1><p class="muted">Your next session, match, or team moment starts here.</p></div>${admin?'<button class="btn btn-primary" id="add-schedule">+ Add session</button>':''}</div><div class="toolbar"><label class="search-field"><span class="sr-only">Search schedules</span><input id="schedule-search" type="search" placeholder="Search sessions or locations…"></label><label><span class="sr-only">Sport</span><select id="schedule-sport"><option value="">All sports</option></select></label><label><span class="sr-only">Session type</span><select id="schedule-type"><option value="">All types</option><option value="training">Training</option><option value="match">Match</option><option value="event">Event</option></select></label><label><span class="sr-only">Sort schedules</span><select id="schedule-order"><option value="asc">Soonest first</option><option value="desc">Latest first</option></select></label></div><div id="schedule-content" aria-live="polite"></div>`;
  const content=container.querySelector('#schedule-content'),search=container.querySelector('#schedule-search'),sport=container.querySelector('#schedule-sport'),type=container.querySelector('#schedule-type'),order=container.querySelector('#schedule-order');
  async function load(){const version=++sequence;
  content.innerHTML='<div class="loading-state" role="status">Loading your schedule…</div>';
  try{const response=await api.listSchedules({page,limit:8,search:search.value,sport:sport.value,type:type.value,sort:'startsAt',order:order.value});
  if(disposed||version!==sequence)return;
  if(response.pagination.totalPages && page > response.pagination.totalPages){page=response.pagination.totalPages;return load();}
      records=response.data;
  content.innerHTML=renderTable([{label:'Session',render:r=>`<strong>${escape(r.title)}</strong><span class="table-subtitle">${escape(r.clubName)} · ${escape(r.sport)}</span>`},{label:'Type',render:r=>statusBadge(r.type)},{label:'When',render:r=>`${escape(date(r.startsAt))}<span class="table-subtitle">Until ${escape(date(r.endsAt))}</span>`},{label:'Location',key:'location'},...(admin?[{label:'Manage',render:r=>`<button class="btn btn-quiet" data-edit="${Number(r.id)}">Edit</button><button class="btn btn-quiet danger-text" data-delete="${Number(r.id)}">Delete</button>`}]:[])],records,{empty:'No sessions match your filters.'})+renderPager(response.pagination);
  }catch(error){if(!disposed&&version===sequence)content.innerHTML=`<div class="error-state" role="alert">${escape(error.message)} <button class="btn btn-secondary" data-retry>Try again</button></div>`;
  }}
  function edit(record={}){if(!clubs.length)return toast('Create a club before scheduling a session.','error');
  const dialog=openModal({title:record.id?'Edit session':'Add training, match, or event',content:`<form class="form-grid">${field('title','Session title',{value:record.title||''})}${field('clubId','Sports club',{value:record.clubId||clubs[0].id,options:clubs.map(c=>({value:c.id,label:c.name}))})}${field('type','Session type',{value:record.type||'training',options:['training','match','event'].map(v=>({value:v,label:v[0].toUpperCase()+v.slice(1)}))})}${field('location','Location',{value:record.location||''})}${field('startsAt','Starts at',{type:'datetime-local',value:inputDate(record.startsAt)})}${field('endsAt','Ends at',{type:'datetime-local',value:inputDate(record.endsAt)})}<div class="form-actions"><button type="submit" class="btn btn-primary">Save session</button></div></form>`});
  bindForm(dialog.querySelector('form'),data=>{if(new Date(data.endsAt)<=new Date(data.startsAt)){const e=new Error('End time must be after the start time.');
  e.errors={endsAt:['Choose a later end time.']};
  throw e;
  }const body={...data,clubId:Number(data.clubId),startsAt:new Date(data.startsAt).toISOString(),endsAt:new Date(data.endsAt).toISOString()};
  return record.id?api.updateSchedule(record.id,body):api.createSchedule(body);
  },{success:'Session saved',onSuccess:()=>{dialog.close();
  load();
  }});
  }
  let timer;
  search.addEventListener('input',()=>{clearTimeout(timer);
  timer=setTimeout(()=>{page=1;
  load();
  },250);
  });
  [sport,type,order].forEach(el=>el.addEventListener('change',()=>{page=1;
  load();
  }));
  container.querySelector('#add-schedule')?.addEventListener('click',()=>edit());
  content.addEventListener('click',async event=>{const b=event.target.closest('button');
  if(!b)return;
  if(b.hasAttribute('data-retry'))return load();
  if(b.dataset.page){page=Number(b.dataset.page);
  return load();
  }const r=records.find(x=>x.id===Number(b.dataset.edit||b.dataset.delete));
  if(!r)return;
  if(b.dataset.edit)return edit(r);
  if(b.dataset.delete){b.disabled=true;
  try{if(await confirmDialog(`Delete ${r.title}?`)){await api.deleteSchedule(r.id);
  toast('Session deleted');
  await load();
  }}catch(e){toast(e.message,'error');
  }finally{b.disabled=false;
  }}});
  try{const response=await api.listClubs({limit:100});
  clubs=response.data;
  if(!disposed)sport.insertAdjacentHTML('beforeend',[...new Set(clubs.map(c=>c.sport))].sort().map(s=>`<option value="${escape(s)}">${escape(s)}</option>`).join(''));
  }catch{/* Load displays errors. */}await load();
  return()=>{disposed=true;
  ++sequence;
  clearTimeout(timer);
  };
}
