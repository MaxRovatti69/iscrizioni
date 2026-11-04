import {entrantCount} from './endu-data.js';
import {parseHistory,compareHistory} from './history-data.js';
import {PISA_URL,validateEvent} from './events-data.js';
const $=id=>document.getElementById(id);
const KEY='iscrizioni-events-v1', INTERVAL=6*60*60*1000;
const format=n=>n.toLocaleString('it-IT',{maximumFractionDigits:1});
const today=date=>new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Rome',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(date));
let events=[{id:'pisa',name:'Maratona di Pisa',url:PISA_URL,history:null}], selected='pisa';
const live=new Map();
let busy=false, timer;
function message(text){$('events-message').textContent=text;}
function save(){try{localStorage.setItem(KEY,JSON.stringify(events));message('Configurazione salvata in questo browser.');}catch{message('Salvataggio non disponibile: le modifiche si perderanno chiudendo la pagina.');}}
try{
  const raw=localStorage.getItem(KEY);
  if(raw){
    const saved=JSON.parse(raw);
    if(!Array.isArray(saved)||!saved.length)throw new Error();
    const ids=new Set();
    for(const event of saved){
      validateEvent(event.name,event.url);
      if(typeof event.id!=='string'||ids.has(event.id))throw new Error();
      ids.add(event.id);
      if(event.history)parseHistory(event.history.text,event.history.mode);
    }
    events=saved;selected=events[0].id;
  }else{
    const previous=JSON.parse(localStorage.getItem('pisa-registration-history-v1'));
    if(previous){parseHistory(previous.text,previous.mode);events[0].history=previous;}
  }
}catch{message('Configurazione precedente non leggibile. È mostrato l’evento Pisa predefinito.');}
const active=()=>events.find(e=>e.id===selected);
function cell(row,text,header=false){const c=document.createElement(header?'th':'td');if(header)c.scope='row';c.textContent=text;row.append(c);return c;}
function render(){
  const body=$('event-rows');body.replaceChildren();
  for(const event of events){
    const state=live.get(event.id), current=state?.count;
    const row=document.createElement('tr');row.dataset.eventId=event.id;
    cell(row,event.name,true);
    cell(row,current===undefined?'—':`${format(current)} iscritti attuali`);
    let baseline=event.history?'Storico caricato':'Nessuno storico caricato', delta='—', percent='—';
    if(event.history){
      try{
        const result=compareHistory(parseHistory(event.history.text,event.history.mode),current??0,today(state?.retrievedAt||Date.now()));
        baseline=`${format(result.past)} ${result.weekDate?`alla settimana del ${result.weekDate} (data obiettivo ${result.target})`:`al ${result.target}`}`;
        if(current!==undefined){delta=`${result.delta>=0?'+':''}${format(result.delta)} iscritti`;percent=result.percent===null?'Non calcolabile: base zero':`${result.percent>=0?'+':''}${format(result.percent)}% rispetto all’anno precedente`;}
      }catch(error){baseline=`Storico caricato · ${error.message}`;}
    }
    cell(row,baseline);cell(row,delta);cell(row,percent);
    cell(row,!event.url?'Iscrizioni non ancora aperte · JSON da configurare':state?.error?`${state.error}${state.retrievedAt?' · Valore precedente del '+new Date(state.retrievedAt).toLocaleString('it-IT',{timeZone:'Europe/Rome'}):''}`:state?.retrievedAt?`Letto il ${new Date(state.retrievedAt).toLocaleString('it-IT',{timeZone:'Europe/Rome'})}`:'In attesa della lettura Endu');
    body.append(row);
  }
}
function settings(){
  $('event-select').replaceChildren();
  for(const event of events){const option=document.createElement('option');option.value=event.id;option.textContent=event.name;$('event-select').append(option);}
  $('event-select').value=selected;
  const event=active();$('event-name').value=event.name;$('event-url').value=event.url;
  $('history-mode').value=event.history?.mode||'weekly';
  $('history-pisa').hidden=event.id!=='pisa';
  $('history-info').textContent=event.history?`${event.name} · ${parseHistory(event.history.text,event.history.mode).rows.length} date storiche caricate`:`${event.name} · Nessuno storico caricato`;
}
async function refresh(){
  if(busy)return;busy=true;clearTimeout(timer);$('endu-refresh').disabled=true;
  $('endu-status').textContent='Aggiornamento degli eventi con JSON configurato…';
  const configured=events.filter(e=>e.url).map(e=>({id:e.id,url:e.url}));
  // Sequential requests keep one dashboard from overwhelming the upstream service.
  for(const event of configured){
    try{
      const response=await fetch('/api/endu/counters?'+new URLSearchParams({url:event.url}),{cache:'no-store',signal:AbortSignal.timeout(25000)});
      if(!response.ok){let detail;try{detail=await response.json();}catch{}throw new Error(detail?.sources?.['entrants.json']?.error||`Lettura Endu non riuscita (HTTP ${response.status})`);}
      const payload=await response.json();
      const count=entrantCount(payload.sources?.['entrants.json']?.data);
      if(events.find(e=>e.id===event.id)?.url===event.url)live.set(event.id,{count,retrievedAt:payload.retrievedAt});
    }catch(error){if(events.find(e=>e.id===event.id)?.url===event.url)live.set(event.id,{...live.get(event.id),error:error.name==='TimeoutError'?'Tempo di risposta scaduto':error.message});}
    render();
  }
  busy=false;$('endu-refresh').disabled=false;
  $('endu-status').textContent='Aggiornamento automatico ogni 6 ore con pagina e server attivi. Gli eventi senza JSON non effettuano richieste. Gli orari indicano la lettura, non l’aggiornamento alla fonte.';
  timer=setTimeout(refresh,INTERVAL);
  lastAttempt=Date.now();
}
let lastAttempt=0;
$('endu-refresh').addEventListener('click',refresh);
document.addEventListener('visibilitychange',()=>{if(!document.hidden&&Date.now()-lastAttempt>=INTERVAL)refresh();});
$('event-select').addEventListener('change',()=>{selected=$('event-select').value;settings();$('history-message').textContent='';});
$('event-create').addEventListener('submit',event=>{
  event.preventDefault();
  try{const config=validateEvent($('event-new-name').value,'');const created={id:crypto.randomUUID(),...config,history:null};events.push(created);selected=created.id;save();$('event-new-name').value='';settings();render();}catch(error){message(error.message);}
});
$('event-settings').addEventListener('submit',event=>{
  event.preventDefault();
  try{const config=validateEvent($('event-name').value,$('event-url').value);const current=active();if(current.url!==config.url)live.delete(current.id);Object.assign(current,config);save();settings();render();refresh();}catch(error){message(error.message);}
});
function setHistory(event,text,mode){
  parseHistory(text,mode);event.history={text,mode};save();settings();render();
  $('history-message').textContent=`Storico associato a ${event.name}.`;
}
$('history-file').addEventListener('change',async event=>{
  const target=active(), mode=$('history-mode').value;
  try{const file=event.target.files[0];if(!file)return;if(file.size>2_000_000)throw new Error('Il CSV deve essere al massimo 2 MB.');setHistory(target,await file.text(),mode);}catch(error){$('history-message').textContent=error.message;}finally{event.target.value='';}
});
$('history-pisa').addEventListener('click',async()=>{
  const target=active();
  try{if(target.id!=='pisa')return;const response=await fetch('data/pisa-2025-settimane.csv');if(!response.ok)throw new Error('Storico non disponibile.');setHistory(target,await response.text(),'weekly');}catch(error){$('history-message').textContent=error.message;}
});
$('history-clear').addEventListener('click',()=>{active().history=null;save();settings();render();$('history-message').textContent='Storico rimosso dall’evento selezionato.';});
settings();render();refresh();
