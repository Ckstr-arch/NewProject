import { store } from '../store.js';
import { CONFIG } from '../config.js';
import { escape } from '../utils/format.js';
import { api } from '../services/api.js';
import { toast } from './toast.js';
import { confirmDialog } from './modal.js';
const paths = {
  dashboard: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  clubs: '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 6"/>',
  memberships: '<path d="M9 5H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-4"/><rect x="9" y="3" width="6" height="5" rx="1"/><path d="m8 15 3 3 5-6"/>',
  schedules: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 4h2m4 0h2m-8 3h2"/>',
  tournaments: '<path d="M8 3h8v6a4 4 0 0 1-8 0zm0 2H4v3a4 4 0 0 0 4 4m8-7h4v3a4 4 0 0 1-4 4m-4 1v6m-5 2h10m-8-2h6"/>',
  results: '<path d="M4 20h16M7 16v-5m5 5V4m5 12V8"/>',
  bookings: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M7 2v4m10-4v4M3 10h18m-13 6 3 3 5-6"/>',
  reports: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 17v-3m4 3v-6m4 6v-4"/>',
  settings: '<path d="m9 3-1 3-3 1-2 3 2 2-1 3 2 3h3l2 3h3l1-3 3-1 2-3-2-2 1-3-2-3h-3l-2-3z"/><circle cx="12" cy="12" r="3"/>',
  logout: '<path d="M9 4H5v16h4m5-12 4 4-4 4m-5-4h9"/>',
  moon: '<path d="M20 15A9 9 0 0 1 9 4a9 9 0 1 0 11 11z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1 1m12 12 1 1M5 19l1-1M18 6l1-1"/>'
};
export function icon(name, className = '') { return `<svg class="icon ${className}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths[name] || paths.clubs}</svg>`; }
export const navigation = [ ['dashboard','Overview'], ['clubs','Sports clubs'], ['memberships','Memberships'], ['schedules','Schedules'], ['tournaments','Tournaments'], ['results','Results & standings'], ['bookings','Facilities & bookings'], ['reports','Reports'], ['settings','Settings'] ];
export function renderShell() {
  const {user} = store.get();
  document.getElementById('app').innerHTML = `<div class="app-shell"><div class="drawer-backdrop" hidden></div><aside class="sidebar" id="sidebar" aria-label="Main navigation"><a class="brand" href="#/dashboard"><span class="brand-mark">ϟ</span><span>NSBM<span class="brand-sub">SPORTSHUB</span></span></a><div class="workspace-label">YOUR SPORTS. YOUR CAMPUS.</div><nav class="nav-list">${navigation.filter(([route]) => CONFIG.API_MODE === 'live' ? ['dashboard','clubs','schedules','tournaments','bookings'].includes(route) : route!=='reports'||user.role==='admin').map(([route,label]) => `<a href="#/${route}" data-route="${route}">${icon(route)}<span>${label}</span></a>`).join('')}</nav><div class="sidebar-bottom"><div class="campus-card"><span class="campus-dot"></span><span>One campus.<br><strong>Endless possibilities.</strong></span><svg viewBox="0 0 100 35" fill="none" aria-hidden="true"><path d="M0 34Q30-8 65 28T110 10M0 42Q30 0 65 36T110 18" stroke="currentColor"/></svg></div><div class="mode-pill">${CONFIG.API_MODE === 'mock' ? '<span></span> Demo Mode' : '<span></span> Live API mode'}</div><button class="logout-btn" id="logout">${icon('logout')}<span>Log out</span></button></div></aside><div class="workspace"><header class="topbar"><div class="topbar-left"><button class="icon-btn menu-toggle" id="menu-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">☰</button><span class="breadcrumb">Workspace <span>/</span> <strong id="breadcrumb-route">Overview</strong></span></div><div class="topbar-right"><span class="today">${escape(new Intl.DateTimeFormat('en-GB',{weekday:'short',day:'numeric',month:'short'}).format(new Date()))}</span><button class="icon-btn" id="theme-toggle" aria-label="Toggle dark theme">${icon(store.get().theme==='dark'?'sun':'moon')}</button><span class="topbar-divider"></span><a href="${CONFIG.API_MODE === 'mock' ? '#/settings' : '#/dashboard'}" class="profile-link"><span class="avatar">${escape(user.name.split(' ').map(word=>word[0]).slice(0,2).join(''))}</span><span class="profile-copy"><strong>${escape(user.name)}</strong><small>${escape(({admin:'Sports administrator',sports_admin:'Sports administrator',super_admin:'Super administrator',club_manager:'Club manager',student:'Student athlete'})[user.role]||user.role)}</small></span></a></div></header><main id="main" tabindex="-1"></main><footer class="app-footer"><span>NSBM SportsHub <span class="muted">/ Made for your next move.</span></span><span>GREEN UNIVERSITY · SRI LANKA</span></footer></div></div>`;
  const menu = document.getElementById('menu-toggle'), backdrop=document.querySelector('.drawer-backdrop');
  function close() { document.body.classList.remove('drawer-open'); backdrop.hidden=true; menu.setAttribute('aria-expanded','false'); }
  menu.onclick=() => { const open=!document.body.classList.contains('drawer-open'); document.body.classList.toggle('drawer-open',open); backdrop.hidden=!open; menu.setAttribute('aria-expanded',String(open)); if(open) document.querySelector('.nav-list a[aria-current="page"]')?.focus(); };
  backdrop.onclick=()=>{close();menu.focus();};
  document.querySelector('.sidebar').addEventListener('keydown', event=>{
    if(event.key==='Escape'){close();menu.focus();}
    if(event.key==='Tab' && document.body.classList.contains('drawer-open')){
      const links=[...document.querySelectorAll('.sidebar a,.sidebar button')];
      if(event.shiftKey && document.activeElement===links[0]){event.preventDefault();links.at(-1).focus();}
      if(!event.shiftKey && document.activeElement===links.at(-1)){event.preventDefault();links[0].focus();}
    }
  });
  document.querySelectorAll('.nav-list a').forEach(link=>link.addEventListener('click',close));
  document.getElementById('theme-toggle').onclick=() => { store.setTheme(store.get().theme==='dark'?'light':'dark'); document.getElementById('theme-toggle').innerHTML=icon(store.get().theme==='dark'?'sun':'moon'); };
  document.getElementById('logout').onclick=async()=>{ if(!await confirmDialog('Log out of your SportsHub session?'))return; try{await api.logout();}catch(error){toast(error.message,'error');}finally{store.set({user:null,permissions:[]});location.hash='/login';} };
}
// Keep the shell in sync when a profile is saved without rebuilding the current view.
store.subscribe(({user,theme}) => {
  if(user){
    const name=document.querySelector('.profile-copy strong');
    const avatar=document.querySelector('.profile-link .avatar');
    if(name)name.textContent=user.name;
    if(avatar)avatar.textContent=user.name.split(' ').map(word=>word[0]).slice(0,2).join('');
  }
  const toggle=document.getElementById('theme-toggle');
  if(toggle){toggle.innerHTML=icon(theme==='dark'?'sun':'moon');toggle.setAttribute('aria-label',theme==='dark'?'Switch to light theme':'Switch to dark theme');}
});
export function updateNavigation(route) {
  document.querySelectorAll('[data-route]').forEach(link=>{const active=link.dataset.route===route;link.classList.toggle('active',active);if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');});
  const breadcrumb=document.getElementById('breadcrumb-route');
  if(breadcrumb)breadcrumb.textContent=navigation.find(([key])=>key===route)?.[1]||'Page not found';
}
