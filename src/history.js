import {parseHistory,compareHistory} from './history-data.js';
const $ = id=>document.getElementById(id);
const storageKey = 'pisa-registration-history-v1';
let history = null, current = null, retrievedAt = null;
function render() {
  $('history-result').textContent='';
  if (!history) return;
  $('history-info').textContent=`${history.rows.length} date caricate · ${history.rows[0].date} → ${history.rows.at(-1).date} · ${history.mode==='weekly'?'Iscrizioni settimanali':history.mode==='daily'?'Iscrizioni giornaliere':'Totali cumulati'}`;
  if (current===null) { $('history-result').textContent='In attesa del totale Endu.'; return; }
  try {
    const result = compareHistory(history,current,new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Rome',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(retrievedAt)));
    const format=n=>n.toLocaleString('it-IT',{maximumFractionDigits:1});
    $('history-result').textContent=`${format(current)} iscritti attuali · ${format(result.past)} ${result.weekDate ? `alla settimana del ${result.weekDate} (data obiettivo ${result.target})` : `al ${result.target}`} · ${result.delta>=0?'+':''}${format(result.delta)} iscritti · ${result.percent===null?'Percentuale non calcolabile: base storica zero':`${result.percent>=0?'+':''}${format(result.percent)}% rispetto all’anno precedente`}`;
  } catch(error) { $('history-result').textContent=error.message; }
}
document.addEventListener('endu-count',event=>{current=event.detail.count;retrievedAt=event.detail.retrievedAt;render();});
$('history-file').addEventListener('change',async event=>{
  try {
    const file=event.target.files[0]; if(!file)return;
    if(file.size>2_000_000)throw new Error('Il CSV deve essere al massimo 2 MB.');
    const text=await file.text(), mode=$('history-mode').value;
    const parsed=parseHistory(text,mode);
    history=parsed; render();
    try { localStorage.setItem(storageKey,JSON.stringify({text,mode}));$('history-message').textContent='Storico importato e salvato in questo browser.'; }
    catch { $('history-message').textContent='Storico importato, ma salvataggio nel browser non disponibile.'; }
  } catch(error){$('history-message').textContent=error.message;}
  finally{event.target.value='';}
});
$('history-clear').addEventListener('click',()=>{history=null;try{localStorage.removeItem(storageKey);}catch{}$('history-info').textContent='Nessuno storico caricato.';$('history-message').textContent='Storico rimosso.';render();});
try {const saved=JSON.parse(localStorage.getItem(storageKey));if(saved)history=parseHistory(saved.text,saved.mode);render();}catch{$('history-message').textContent='Storico salvato non disponibile. Importa nuovamente il CSV.';}

$('history-pisa').addEventListener('click',async()=>{
  try {
    const response=await fetch('data/pisa-2025-settimane.csv');
    if(!response.ok)throw new Error('Storico Pisa non disponibile.');
    const text=await response.text();
    history=parseHistory(text,'weekly');$('history-mode').value='weekly';render();
    $('history-message').textContent='Storico 2025 dal file caricato. Valori interpretati come nuove iscrizioni settimanali, con riferimento alla data gara meno N settimane. Verifica che includa le stesse gare del totale Endu.';
    try{localStorage.setItem(storageKey,JSON.stringify({text,mode:'weekly'}));}catch{$('history-message').textContent+=' Salvataggio nel browser non disponibile.';}
  }catch(error){$('history-message').textContent=error.message;}
});
