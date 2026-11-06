import { api } from './services/api.js';
import { store } from './store.js';
import { startRouter } from './router.js';
import { escape } from './utils/format.js';
import { toast } from './components/toast.js';
store.setTheme(store.get().theme);
document.querySelector('.skip-link').addEventListener('click', event => {
  event.preventDefault(); document.getElementById('main')?.focus();
});
window.addEventListener('sportshub:unauthorized',()=>{
  const wasSignedIn=!!store.get().user; store.set({user:null,permissions:[]});
  if(wasSignedIn){toast('Your session has expired. Please sign in again.','error');location.hash='/login';}
});
async function bootstrap(){
  try{
    const response=await api.session();
    store.set({user:response.data.user,permissions:response.data.permissions||[],notifications:response.data.user?.notifications??true});
    startRouter();
  }catch(error){
    if(error.status===401){store.set({user:null,permissions:[]});startRouter();return;}
    document.getElementById('app').innerHTML=`<main id="main" tabindex="-1" class="boot-screen"><span class="brand-mark">ϟ</span><h1>SportsHub is taking a timeout.</h1><p>${escape(error.message||'Could not restore your session.')}</p><button class="btn btn-primary" id="retry-bootstrap">Try again</button><p class="muted">Live mode requires a compatible backend. No demo data was substituted.</p></main>`;
    document.getElementById('retry-bootstrap').onclick=bootstrap;
  }
}
bootstrap();
