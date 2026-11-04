import {parseCSV,mergeRows,toCSV} from './data.js';
const $ = id => document.getElementById(id);
let data = [], demo = false;
const money = n => n.toLocaleString('it-IT',{style:'currency',currency:'EUR'});
const escape = s => String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function filtered() { const q=$('search').value.toLocaleLowerCase('it'); return data.filter(r=>(!$('event').value || r.event===$('event').value) && (!$('status').value || r.status===$('status').value) && `${r.name} ${r.email}`.toLocaleLowerCase('it').includes(q)); }
function render() {
  const rows=filtered(), paid=rows.filter(r=>r.status==='Pagato');
  $('total').textContent=rows.length.toLocaleString('it-IT'); $('events').textContent=new Set(rows.map(r=>r.event)).size;
  $('paid').textContent=paid.length; $('paid-share').textContent=rows.length ? `${Math.round(paid.length/rows.length*100)}% delle iscrizioni` : 'Nessuna iscrizione';
  $('revenue').textContent=money(paid.reduce((s,r)=>s+r.amount,0));
  $('rows').innerHTML=rows.map(r=>`<tr><td><div class="person"><span class="initials">${escape(r.name.split(' ').map(n=>n[0]).slice(0,2).join(''))}</span><div><b>${escape(r.name)}</b><small>${escape(r.email || 'Email non disponibile')}</small></div></div></td><td>${escape(r.event)}</td><td>${escape(r.date || '—')}</td><td><span class="badge ${r.status==='Pagato'?'paid':r.status==='In attesa'?'pending':''}">${escape(r.status)}</span></td><td class="amount">${money(r.amount)}</td></tr>`).join('');
  $('empty').hidden=rows.length>0;
  $('empty').querySelector('h3').textContent=data.length ? 'Nessuna iscrizione trovata.' : 'Il prossimo traguardo parte da qui.';
  $('empty').querySelector('p').textContent=data.length ? 'Prova a cambiare i filtri o la ricerca.' : 'Importa il tuo primo CSV o esplora i dati dimostrativi.';
  $('count').textContent=`${rows.length} di ${data.length} iscrizioni`;
  $('source').textContent=demo?'Dati dimostrativi · Non collegati a Endu':data.length?'Dati importati · Solo in questa scheda':'Nessun dato importato';
  $('export').disabled=!rows.length; $('clear').disabled=!data.length;
}
function refreshEvents(){const value=$('event').value; $('event').innerHTML='<option value="">Tutti gli eventi</option>'+[...new Set(data.map(r=>r.event))].sort().map(e=>`<option>${escape(e)}</option>`).join(''); $('event').value=[...$('event').options].some(o=>o.value===value)?value:'';render();}
for(const id of ['search','event','status']) $(id).addEventListener('input',render);
$('file').addEventListener('change',async e=>{
  try {
    const incoming=[];
    for(const file of e.target.files) {if(file.size>10*1024*1024) throw new Error('Ogni CSV può essere al massimo 10 MB.'); incoming.push(...parseCSV(await file.text()));}
    if(!incoming.length) return;
    data=mergeRows(demo?[]:data,incoming); demo=false;refreshEvents();$('message').textContent=`Importazione completata: ${incoming.length} righe elaborate. Eventuali duplicati sono stati aggiornati.`;
  } catch(error){$('message').textContent=error.message;} finally{e.target.value='';}
});
$('demo').addEventListener('click',()=>{
  if(data.length && !demo){$('message').textContent='Svuota i dati importati prima di caricare la demo.';return;}
  const names=['Giulia Bianchi','Marco Rossi','Elena Ferrari','Luca Romano','Sara Conti','Andrea Ricci','Chiara Moretti','Paolo Galli'];
  data=names.map((name,i)=>({id:String(i),name,email:`partecipante${i+1}@example.com`,event:['Trail delle Colline','City Run 10K','Gran Fondo del Lago'][i%3],date:`0${i+1}/11/2026`,status:i%3===0?'In attesa':'Pagato',amount:[35,20,50][i%3]}));demo=true;$('message').textContent='Stai esplorando dati fittizi. Importando un CSV verranno sostituiti.';refreshEvents();
});
$('clear').addEventListener('click',()=>{if(!confirm('Svuotare tutti i dati della scheda?'))return;data=[];demo=false;$('search').value='';$('status').value='';$('message').textContent='Dati svuotati.';refreshEvents();});
$('export').addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([toCSV(filtered())],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=demo?'iscrizioni-demo.csv':'iscrizioni.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
render();
