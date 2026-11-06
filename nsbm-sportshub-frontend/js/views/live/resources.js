import { api } from '../../services/api.js';
import { store } from '../../store.js';
import { escape, field, bindForm, renderPager, renderTable, statusBadge } from '../../components/table.js';
import { openModal, confirmDialog } from '../../components/modal.js';
import { toast } from '../../components/toast.js';
import { can, canEditClub } from './permissions.js';

const facilities = ['Main Ground','Indoor Court','Gymnasium','Swimming Pool','Tennis Court','Badminton Court'];
const statuses = {
  clubs: ['active','inactive'], schedules: ['scheduled','live','completed','cancelled'],
  tournaments: ['registration_open','upcoming','ongoing','completed','cancelled'],
  bookings: ['pending','confirmed','cancelled','completed']
};
const definitions = {
  clubs: { singular:'Club', title:'Sports clubs', eyebrow:'FIND YOUR COMMUNITY', subtitle:'Discover your team and make your next move.', createId:'add-club', sort:'name', sorts:['name','sport','member_count','status','created_at'] },
  schedules: { singular:'Schedule', title:'Training & schedules', eyebrow:'MAKE TIME FOR YOUR GAME', subtitle:'Training sessions, matches, and campus sports events.', createId:'new-schedule', sort:'event_date', sorts:['event_name','sport','event_date','status','created_at'] },
  tournaments: { singular:'Tournament', title:'Tournaments', eyebrow:'BIG GAMES. SHARED AMBITION.', subtitle:'Discover university competitions and follow the sporting season.', createId:'add-tournament', sort:'start_date', sorts:['name','sport','start_date','status','created_at'] },
  bookings: { singular:'Booking', title:'Facilities & bookings', eyebrow:'SPACE TO DO YOUR THING', subtitle:'Reserve a campus sports facility. Pending and confirmed bookings reserve their time slot.', createId:'new-booking', sort:'booking_date', sorts:['facility','sport','booking_date','status','created_at'] }
};
const label = name => name.replaceAll('_',' ').replace(/^./, letter => letter.toUpperCase());
const options = values => values.map(value => ({value,label:label(value)}));
const today = () => new Intl.DateTimeFormat('en-CA',{year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
const f = (record,name,title,extra={}) => field(name,title,{value:record[name]??'',...extra});
const select = (record,name,title,values,defaultValue) => f(record,name,title,{options:options(values),value:record[name]??defaultValue??values[0]});

function formFields(kind, record, administrator) {
  const text = (name,title,required=true) => f(record,name,title,{required});
  const description = (name='description',title='Description') => f(record,name,title,{type:'textarea',required:false,max:2000});
  const sport = text('sport','Sport');
  const status = select(record,'status','Status',statuses[kind]);
  if(kind==='clubs') return text('name','Club name')+sport+text('president','President')+f(record,'member_count','Member count',{type:'number',min:0,value:record.member_count??0})+status+description()+f(record,'logo_url','Logo URL',{type:'url',required:false,max:500});
  if(kind==='schedules') return sport+text('event_name','Event name')+f(record,'event_date','Event date',{type:'date',value:record.event_date||today()})+f(record,'start_time','Start time',{type:'time',value:(record.start_time||'15:00').slice(0,5)})+f(record,'end_time','End time',{type:'time',value:(record.end_time||'17:00').slice(0,5)})+text('location','Location')+text('teams','Teams',false)+status+description();
  if(kind==='tournaments') return text('name','Tournament name')+sport+f(record,'start_date','Start date',{type:'date',value:record.start_date||today()})+f(record,'end_date','End date',{type:'date',value:record.end_date||today()})+text('format','Format')+f(record,'max_teams','Maximum teams',{type:'number',min:1,value:record.max_teams??16})+f(record,'registered_teams','Registered teams',{type:'number',min:0,value:record.registered_teams??0})+status+description();
  return select(record,'facility','Facility',facilities)+sport+f(record,'booking_date','Booking date',{type:'date',value:record.booking_date||today()})+f(record,'start_time','Start time',{type:'time',value:(record.start_time||'15:00').slice(0,5)})+f(record,'end_time','End time',{type:'time',value:(record.end_time||'17:00').slice(0,5)})+(administrator ? status+f(record,'booked_by','Book on behalf of user ID',{type:'number',min:1,required:false,value:record.booked_by||''}) : '<p class="muted">Your reservation will be submitted as pending for your own account.</p>')+description('notes','Notes');
}

function writeBody(kind, data) {
  const body={...data};
  for(const key of ['member_count','max_teams','registered_teams','booked_by']) if(key in body) {if(body[key]==='') delete body[key];else body[key]=Number(body[key]);}
  if(kind==='bookings'&&!can('bookings.update')) body.status='pending';
  return body;
}

export async function render(container,kind) {
  const definition=definitions[kind];
  const user=store.get().user;
  const administrator=can('bookings.update');
  const query={page:1,limit:kind==='clubs'||kind==='tournaments'?6:10,search:'',status:'',sport:'',facility:'',date:'',sort:definition.sort,order:'asc'};
  let sequence=0,disposed=false,records=[],searchTimer;
  const method=action=>`${action}${action==='list'?kind[0].toUpperCase()+kind.slice(1):definition.singular}`;
  const editable=record=>kind==='clubs'?canEditClub(record):can(`${kind}.update`);
  const cancellable=record=>kind==='bookings'&&Number(record.booked_by)===Number(user.id)&&['pending','confirmed'].includes(record.status);
  const title=kind==='bookings'&&!administrator?'My bookings':definition.title;
  container.innerHTML=`<div class="page-heading"><div><p class="eyebrow">${definition.eyebrow}</p><h1>${title}</h1><p class="muted">${definition.subtitle}</p></div>${can(`${kind}.create`)?`<button class="btn btn-primary" id="${definition.createId}">+ Create ${definition.singular.toLowerCase()}</button>`:''}</div>
    <form class="toolbar live-filter" id="resource-filter" aria-label="Filter ${kind}"><label class="search-field"><span class="sr-only">Search ${kind}</span><input type="search" name="search" id="${kind==='clubs'?'club-search':kind==='tournaments'?'tournament-search':'resource-search'}" placeholder="Search ${kind}…"></label><label><span class="sr-only">Status</span><select name="status"><option value="">All statuses</option>${statuses[kind].map(value=>`<option value="${value}">${label(value)}</option>`).join('')}</select></label>${kind==='bookings'?`<label><span class="sr-only">Facility</span><select name="facility"><option value="">All facilities</option>${facilities.map(value=>`<option>${escape(value)}</option>`).join('')}</select></label>`:'<label><span class="sr-only">Sport</span><input name="sport" placeholder="Filter by sport"></label>'}${kind==='clubs'?'':`<label><span class="sr-only">Date</span><input type="date" name="date"></label>`}<label><span class="sr-only">Sort by</span><select name="sort">${definition.sorts.map(value=>`<option value="${value}" ${value===query.sort?'selected':''}>${label(value)}</option>`).join('')}</select></label><label><span class="sr-only">Order</span><select name="order"><option value="asc">Ascending</option><option value="desc">Descending</option></select></label><button class="btn btn-secondary" type="submit">Apply filters</button></form><p class="muted" id="resource-count" aria-live="polite"></p><div id="resource-content" aria-live="polite"></div>`;
  const content=container.querySelector('#resource-content');

  function actions(record) {
    return `<div class="row-actions"><button class="btn btn-secondary btn-small" data-details="${Number(record.id)}">View details</button>${editable(record)?`<button class="btn btn-quiet btn-small" data-edit="${Number(record.id)}">Edit</button>`:''}${can(`${kind}.delete`)?`<button class="btn btn-quiet btn-small danger-text" data-delete="${Number(record.id)}">Delete</button>`:''}${cancellable(record)?`<button class="btn btn-secondary btn-small" data-cancel="${Number(record.id)}">Cancel booking</button>`:''}</div>`;
  }
  function cards() {
    return `<div class="cards ${kind==='clubs'?'club-grid':'tournament-grid'}">${records.map((record,index)=>`<article class="card ${kind==='clubs'?'club-card':'tournament-card'}">${kind==='clubs'?`<div class="club-art sport-art-${index%6}"><span class="sport-monogram" aria-hidden="true">${escape(record.sport.slice(0,2).toUpperCase())}</span><span class="sport-chip">${escape(record.sport)}</span></div>`:`<div class="section-heading"><span class="badge">${escape(record.sport)}</span>${statusBadge(record.status)}</div>`}<div class="${kind==='clubs'?'club-card-body':''}"><h2>${escape(record.name)}</h2><p class="muted club-description">${escape(record.description)}</p><dl class="detail-list">${kind==='clubs'?`<dt>President</dt><dd>${escape(record.president)}</dd><dt>Members</dt><dd>${escape(record.member_count)}</dd><dt>Status</dt><dd>${statusBadge(record.status)}</dd>`:`<dt>Dates</dt><dd>${escape(record.start_date)} to ${escape(record.end_date)}</dd><dt>Format</dt><dd>${escape(record.format)}</dd><dt>Teams</dt><dd>${escape(record.registered_teams)} / ${escape(record.max_teams)}</dd>`}</dl><div class="card-actions">${actions(record)}</div></div></article>`).join('')}</div>`;
  }
  function table() {
    return renderTable(kind==='schedules'?[
      {label:'Event',key:'event_name'},{label:'Sport',key:'sport'},
      {label:'When',render:row=>`${escape(row.event_date)}<br>${escape(row.start_time.slice(0,5))} – ${escape(row.end_time.slice(0,5))}`},
      {label:'Location',key:'location'},{label:'Status',render:row=>statusBadge(row.status)},{label:'Actions',render:actions}
    ]:[
      {label:'Facility',key:'facility'},...(administrator?[{label:'Booked by',render:row=>escape(row.booked_by_name||row.booked_by)}]:[]),
      {label:'When',render:row=>`${escape(row.booking_date)}<br>${escape(row.start_time.slice(0,5))} – ${escape(row.end_time.slice(0,5))}`},
      {label:'Sport',key:'sport'},{label:'Notes',key:'notes'},{label:'Status',render:row=>statusBadge(row.status)},{label:'Actions',render:actions}
    ],records);
  }
  async function load() {
    const ticket=++sequence;
    content.setAttribute('aria-busy','true');
    try {
      const {data}=await api[method('list')](query);
      if(disposed||ticket!==sequence)return;
      if(data.pages&&query.page>data.pages){query.page=data.pages;return load();}
      records=data.items;
      container.querySelector('#resource-count').textContent=`${data.total} ${kind}`;
      content.innerHTML=(records.length?(kind==='clubs'||kind==='tournaments'?cards():table()):'<div class="empty-state"><h2>No records found</h2><p>Try changing your filters or create a new record.</p></div>')+renderPager({...data,totalPages:data.pages});
    }catch(error){if(!disposed&&ticket===sequence)content.innerHTML=`<div class="error-state" role="alert"><p>${escape(error.message)}</p><button class="btn btn-secondary" data-retry>Try again</button></div>`;}
    finally{if(!disposed&&ticket===sequence)content.removeAttribute('aria-busy');}
  }
  function edit(record={}) {
    const dialog=openModal({title:`${record.id?'Edit':'Create'} ${definition.singular.toLowerCase()}`,content:`<form class="form-grid">${formFields(kind,record,administrator)}<div class="form-actions"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button class="btn btn-primary" type="submit">${record.id?'Save changes':'Create '+definition.singular.toLowerCase()}</button></div></form>`});
    dialog.querySelector('[data-close]').onclick=()=>dialog.close();
    bindForm(dialog.querySelector('form'),data=>record.id?api[method('update')](record.id,writeBody(kind,data)):api[method('create')](writeBody(kind,data)),{success:`${definition.singular} saved.`,onSuccess:async()=>{dialog.close();await load();}});
  }
  const filter=container.querySelector('#resource-filter');
  const apply=()=>{Object.assign(query,Object.fromEntries(new FormData(filter)));query.page=1;load();};
  filter.addEventListener('submit',event=>{event.preventDefault();clearTimeout(searchTimer);apply();});
  filter.elements.search.addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(apply,250);});
  container.querySelector(`#${definition.createId}`)?.addEventListener('click',()=>edit());
  content.addEventListener('click',async event=>{
    const button=event.target.closest('button');if(!button||button.disabled)return;
    if(button.hasAttribute('data-retry'))return load();
    if(button.dataset.page){query.page=Number(button.dataset.page);return load();}
    const id=Number(button.dataset.details||button.dataset.edit||button.dataset.delete||button.dataset.cancel);
    const record=records.find(row=>Number(row.id)===id);if(!record)return;
    if(button.dataset.edit)return edit(record);
    button.disabled=true;
    try{
      if(button.dataset.details){const{data}=await api[method('get')](id);if(disposed)return;openModal({title:data.name||data.event_name||`${definition.singular} #${data.id}`,content:`<dl class="detail-list">${Object.entries(data).filter(([key])=>!['id','manager_id','logo_url'].includes(key)).map(([key,value])=>`<dt>${escape(label(key))}</dt><dd>${escape(value??'—')}</dd>`).join('')}</dl>`});}
      else if(button.dataset.delete&&await confirmDialog(`Delete this ${definition.singular.toLowerCase()}?`)){await api[method('delete')](id);toast(`${definition.singular} deleted.`);await load();}
      else if(button.dataset.cancel&&await confirmDialog('Cancel this booking and release the reserved time?')){await api.updateBooking(id,{status:'cancelled'});toast('Booking cancelled.');await load();}
    }catch(error){toast(error.message,'error');}finally{button.disabled=false;}
  });
  await load();
  return()=>{disposed=true;++sequence;clearTimeout(searchTimer);};
}
