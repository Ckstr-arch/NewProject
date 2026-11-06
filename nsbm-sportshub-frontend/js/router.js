import { store } from './store.js';
import { CONFIG } from './config.js';
import { renderShell, updateNavigation } from './components/navbar.js';
import { escape } from './utils/format.js';
const routes = {
  login: () => import('./views/login.js'), dashboard: () => import('./views/dashboard.js'),
  clubs: () => import('./views/clubs.js'), memberships: () => import('./views/memberships.js'),
  schedules: () => import('./views/schedules.js'), tournaments: () => import('./views/tournaments.js'),
  results: () => import('./views/results.js'), bookings: () => import('./views/bookings.js'),
  reports: () => import('./views/reports.js'), settings: () => import('./views/settings.js')
};
let generation=0, cleanup;
export async function navigate() {
  const ticket=++generation;
  document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
  if(cleanup){cleanup();cleanup=null;}
  const {user}=store.get();
  const route=location.hash.replace(/^#\/?/,'').split('?')[0] || (user?'dashboard':'login');
  if(!user&&route!=='login'){location.replace('#/login');return;}
  if(user&&route==='login'){location.replace('#/dashboard');return;}
  store.set({route,loading:true}); document.body.classList.remove('drawer-open');
  const app=document.getElementById('app');
  if(route==='login') app.innerHTML='<main id="main" tabindex="-1"></main>';
  else if(!document.querySelector('.app-shell'))renderShell();
  updateNavigation(route);
  const main=document.getElementById('main');
  const title=route==='dashboard'?'Overview':route.charAt(0).toUpperCase()+route.slice(1);
  document.title=`${title} · NSBM SportsHub`;
  if(CONFIG.API_MODE === 'live' && !['login','dashboard','clubs','schedules','tournaments','bookings'].includes(route)){
    main.innerHTML='<div class="empty-state"><h1>Page unavailable</h1><p>This module belongs to the standalone demo and is outside the current backend API.</p><a class="btn btn-primary" href="#/dashboard">Back to overview</a></div>';store.set({loading:false});return;
  }
  if(route==='reports'&&user?.role!=='admin'){
    main.innerHTML='<div class="empty-state"><h1>Access denied</h1><p>Reports are available to sports administrators.</p><a class="btn btn-primary" href="#/dashboard">Back to overview</a></div>';store.set({loading:false});return;
  }
  if(!routes[route]){main.innerHTML='<div class="empty-state"><h1>Page not found</h1><p>Let’s get you back in the game.</p><a class="btn btn-primary" href="#/dashboard">Back to overview</a></div>';store.set({loading:false});return;}
  main.innerHTML='<div class="loading-state" role="status"><span class="spinner"></span>Loading your workspace…</div>';
  try{
    const view=CONFIG.API_MODE === 'live' && route !== 'login'
      ? await import(route === 'dashboard' ? './views/live/dashboard.js' : './views/live/resources.js')
      : await routes[route](); if(ticket!==generation)return;
    // Detached container prevents a slower previous route from replacing the current route.
    const target=document.createElement('div');target.className='view';
    const teardown=await view.render(target,route);
    if(ticket!==generation){if(typeof teardown==='function')teardown();return;}
    main.replaceChildren(target);cleanup=typeof teardown==='function'?teardown:null;
    if(route!=='login') { main.focus({preventScroll:true});window.scrollTo({top:0}); }
  }catch(error){
    if(ticket!==generation)return;
    main.innerHTML=`<div class="error-state" role="alert"><h1>We couldn’t load this page.</h1><p>${escape(error.message||'Please try again.')}</p><button class="btn btn-primary" id="retry-route">Try again</button></div>`;
    document.getElementById('retry-route').onclick=navigate;
  }finally{if(ticket===generation)store.set({loading:false});}
}
export function startRouter(){window.addEventListener('hashchange',navigate);navigate();}
