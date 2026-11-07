/* React browser build: no JSX compiler, Node.js, npm or bundler required. */
/* global React, ReactDOM */
'use strict';
const h = React.createElement;
const {useState, useEffect, useRef} = React;
const format = n => Number(n).toLocaleString('it-IT', {maximumFractionDigits: 1});
const signed = n => `${n >= 0 ? '+' : ''}${format(n)}`;
const romeDate = () => new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit'}).format(new Date());
const csrf = document.querySelector('meta[name="csrf-token"]').content;
async function api(action, data, date) {
  const query = new URLSearchParams({action});
  if (date) query.set('date', date);
  const response = await fetch(`index.php?${query}`, data === undefined ? {cache: 'no-store'} : {
    method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(data)
  });
  if (response.status === 401) { window.location.assign('index.php'); throw new Error('Sessione scaduta.'); }
  const payload = await response.json();
  if (!response.ok) throw new Error(payload.error || `Operazione non riuscita (HTTP ${response.status}).`);
  return payload;
}
function EventRow({event, stats, select}) {
  const comparison = stats?.comparison;
  const daily = stats?.yesterday;
  const baseline = comparison ? `${format(comparison.past)} ${comparison.weekDate ? `alla settimana del ${comparison.weekDate} (data obiettivo ${comparison.target})` : `al ${comparison.target}`}` : event.history ? stats?.historyError || 'Storico caricato' : 'Nessuno storico caricato';
  const dailyText = daily?.count == null ? 'Non disponibile' : `${format(daily.count)}${daily.kind === 'estimated' ? ' (stima)' : ' (importato)'}`;
  const status = !event.url ? 'Iscrizioni non ancora aperte · JSON da configurare' : [stats?.error, stats?.stale ? 'Dato precedente, non aggiornato' : '', stats?.observedAt ? `Letto il ${new Date(stats.observedAt).toLocaleString('it-IT', {timeZone: 'Europe/Rome'})}` : 'Nessuna rilevazione disponibile'].filter(Boolean).join(' · ');
  return h('tr', {'data-event-id': event.id},
    h('th', {scope: 'row'}, h('button', {className: 'event-link', onClick: () => select(event.id)}, event.name)),
    h('td', null, stats?.count == null ? '—' : `${format(stats.count)} iscritti${stats.stale ? ' (precedenti)' : ''}`),
    h('td', {title: daily?.note}, dailyText, daily ? ` · ${daily.date}` : ''),
    h('td', null, baseline),
    h('td', null, comparison?.delta == null ? '—' : `${signed(comparison.delta)} iscritti`),
    h('td', null, comparison?.percent == null ? (comparison && comparison.past === 0 ? 'Base storica zero' : '—') : `${signed(comparison.percent)}% rispetto all’anno precedente`),
    h('td', null, status)
  );
}
function App() {
  const [state, setState] = useState(null);
  const [date, setDate] = useState(romeDate);
  const [selected, setSelected] = useState('');
  const [newName, setNewName] = useState('');
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [mode, setMode] = useState('weekly');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const busy = useRef(false), requestId = useRef(0), stateRef = useRef(null), dateRef = useRef(date), lastLoad = useRef(0);
  dateRef.current = date;
  const event = state?.events.find(item => item.id === selected) || state?.events[0];
  const apply = value => { stateRef.current = value; setState(value); setSelected(previous => value.events.some(item => item.id === previous) ? previous : value.events[0].id); };
  async function load(target = dateRef.current) {
    const id = ++requestId.current;
    try { const value = await api('state', undefined, target); if (id === requestId.current) apply(value); }
    catch (error) { setMessage(error.message); }
  }
  async function refresh(force = false) {
    if (busy.current) return;
    busy.current = true; setLoading(true);
    try {
      await api('refresh', {force}, dateRef.current);
      lastLoad.current = Date.now();
      await load();
    } catch (error) { setMessage(error.message); }
    finally { busy.current = false; setLoading(false); }
  }
  useEffect(() => { load(date); }, [date]);
  useEffect(() => {
    refresh(false);
    const timer = setInterval(() => refresh(false), 6 * 60 * 60 * 1000);
    const onVisible = () => { if (!document.hidden && Date.now() - lastLoad.current >= 6 * 60 * 60 * 1000) refresh(false); };
    document.addEventListener('visibilitychange', onVisible);
    return () => { clearInterval(timer); document.removeEventListener('visibilitychange', onVisible); };
  }, []);
  useEffect(() => { if (event) { setName(event.name); setUrl(event.url); setMode(event.history?.mode || 'weekly'); } }, [event?.id, event?.name, event?.url, event?.history?.mode]);
  async function saveEvents(events) {
    if (saving) return;
    setSaving(true);
    try {
      await api('save', {events, revision: stateRef.current.revision});
      await load(); setMessage('Eventi e storici salvati sul server.');
    } catch (error) { setMessage(`${error.message} Le modifiche non sono state salvate.`); await load(); throw error; }
    finally { setSaving(false); }
  }
  async function updateEvent(updated) { await saveEvents(stateRef.current.events.map(item => item.id === updated.id ? updated : item)); }
  async function create(e) {
    e.preventDefault();
    try { const id = crypto.randomUUID(); await saveEvents([...stateRef.current.events, {id, name: newName.trim(), url: '', history: null}]); setSelected(id); setNewName(''); }
    catch (_) { /* Error already displayed by saveEvents. */ }
  }
  async function configure(e) {
    e.preventDefault();
    try { await updateEvent({...event, name: name.trim(), url: url.trim()}); await refresh(false); }
    catch (_) { /* Error already displayed. */ }
  }
  async function importCSV(input, daily = false) {
    const file = input.files[0], target = event;
    if (!file || !target) return;
    try {
      if (file.size > 2000000) throw new Error('Il file deve essere al massimo 2 MB.');
      const text = await file.text();
      if (daily) { await api('daily', {eventId: target.id, text}); await load(); setMessage(`Conteggi giornalieri importati per ${target.name}.`); }
      else await updateEvent({...target, history: {mode, text}});
    } catch (error) { setMessage(error.message); }
    finally { input.value = ''; }
  }
  async function seed() { try { const result = await api('seed'); await updateEvent({...event, history: {mode: 'weekly', text: result.text}}); } catch (error) { setMessage(error.message); } }
  async function importBackup(input) {
    try {
      const file = input.files[0]; if (!file) return;
      if (file.size > 8000000) throw new Error('Backup troppo grande.');
      const backup = JSON.parse(await file.text());
      if (!Array.isArray(backup.events)) throw new Error('Backup non valido.');
      if (confirm('Sostituire tutti gli eventi e storici con quelli del backup? Le rilevazioni già raccolte rimangono nell’archivio.')) await saveEvents(backup.events);
    } catch (error) { setMessage(error.message); } finally { input.value = ''; }
  }
  async function migrateBrowser() {
    try { const saved = JSON.parse(localStorage.getItem('iscrizioni-events-v1')); if (!Array.isArray(saved)) throw new Error('Nessun evento della vecchia versione in questo browser e su questo dominio.'); if (confirm('Sostituire eventi e storici del server con quelli della vecchia anteprima?')) await saveEvents(saved); }
    catch (error) { setMessage(error.message); }
  }
  return h(React.Fragment, null,
    h('aside', null, h('a', {className: 'brand', href: 'index.php'}, 'i', h('span', null, 'scrizioni'), h('b', null, '↗')), h('div', {className: 'workspace'}, 'IL TUO SPAZIO ORGANIZZATORE'), h('a', {className: 'nav active', href: 'index.php'}, '◫  Panoramica'), h('div', {className: 'aside-bottom'}, 'Tutti i tuoi eventi.', h('br'), 'Un unico punto di vista.')),
    h('main', null,
      h('header', null, h('span', null, 'Spazio organizzatore / Panoramica'), h('form', {method: 'post', action: 'index.php?action=logout'}, h('input', {type: 'hidden', name: 'csrf', value: csrf}), h('button', {className: 'secondary'}, 'Esci'))),
      h('section', {className: 'heading'}, h('div', null, h('p', {className: 'eyebrow'}, 'IL POLSO DEI TUOI EVENTI'), h('h1', null, 'Ogni iscrizione, a colpo d’occhio.'), h('p', {className: 'muted'}, 'Totali, iscrizioni di ieri e confronto con l’anno precedente.'))),
      h('p', {className: 'notice', role: 'status', 'aria-live': 'polite'}, message),
      h('section', {className: 'panel endu-panel'},
        h('div', {className: 'panel-heading'}, h('h2', null, 'I tuoi eventi'), h('button', {className: 'secondary', disabled: loading, onClick: () => refresh(true)}, loading ? 'Aggiornamento…' : '↻ Aggiorna Endu')),
        h('div', {className: 'filters'}, h('label', null, 'Data visualizzata ', h('input', {type: 'date', value: date, max: romeDate(), onChange: e => e.target.value && setDate(e.target.value)})), h('button', {className: 'secondary', onClick: () => setDate(romeDate())}, 'Oggi')),
        h('div', {className: 'event-table-wrap', tabIndex: 0, role: 'region', 'aria-label': 'Riepilogo eventi'}, h('table', {className: 'event-table'},
          h('thead', null, h('tr', null, ...['EVENTO', 'ISCRITTI ALLA DATA', 'ISCRIZIONI DI IERI', 'CONFRONTO ANNO PRECEDENTE', 'DIFFERENZA', 'VARIAZIONE', 'STATO / ULTIMA LETTURA'].map(title => h('th', {key: title}, title)))),
          h('tbody', null, state ? state.events.map(item => h(EventRow, {key: item.id, event: item, stats: state.stats[item.id], select: setSelected})) : h('tr', null, h('td', {colSpan: 7}, 'Caricamento…'))))),
        h('p', {className: 'endu-status'}, 'Aggiornamento ogni 6 ore. La colonna “ieri” si riferisce al giorno precedente la data visualizzata, in ora italiana. Le stime indicano saldi netti, non il numero esatto di nuove iscrizioni.'),
        h('p', {className: 'endu-status'}, 'Per la raccolta a browser chiuso e a mezzanotte, configura il cron PHP descritto nelle istruzioni di installazione.')),
      state && h('section', {className: 'panel event-settings'},
        h('div', {className: 'panel-heading'}, h('h2', null, 'Gestisci eventi')),
        h('form', {className: 'filters', onSubmit: create}, h('input', {value: newName, onChange: e => setNewName(e.target.value), placeholder: 'Nome nuovo evento, es. Golfo dell’Isola 2027', 'aria-label': 'Nome nuovo evento', required: true, maxLength: 150}), h('button', {className: 'button', disabled: saving}, '+ Crea evento')),
        h('div', {className: 'filters'}, h('label', {htmlFor: 'event-select'}, 'Evento da configurare'), h('select', {id: 'event-select', value: event.id, onChange: e => setSelected(e.target.value)}, ...state.events.map(item => h('option', {key: item.id, value: item.id}, item.name)))),
        h('form', {className: 'filters', onSubmit: configure}, h('input', {value: name, onChange: e => setName(e.target.value), 'aria-label': 'Nome evento', required: true, maxLength: 150}), h('input', {type: 'url', value: url, onChange: e => setUrl(e.target.value), placeholder: 'Link entrants.json (facoltativo)', 'aria-label': 'Link JSON Endu'}), h('button', {className: 'secondary', disabled: saving}, 'Salva evento')),
        h('p', {className: 'endu-status'}, 'Puoi creare l’evento e caricare lo storico prima dell’apertura iscrizioni. Eventi e storici sono conservati sul server.'),
        h('div', {className: 'panel-heading'}, h('h2', null, `Storico · ${event.name}`)),
        h('div', {className: 'filters'}, h('select', {value: mode, onChange: e => setMode(e.target.value), 'aria-label': 'Tipo di storico'}, h('option', {value: 'weekly'}, 'Iscrizioni della settimana più vicina'), h('option', {value: 'daily'}, 'Iscrizioni del giorno'), h('option', {value: 'cumulative'}, 'Totale cumulato')),
          h('label', {className: 'button'}, 'Importa storico CSV', h('input', {hidden: true, type: 'file', accept: '.csv', disabled: saving, onChange: e => importCSV(e.target)})),
          event.id === 'pisa' && h('button', {className: 'secondary', disabled: saving, onClick: seed}, 'Usa storico Pisa 2025'),
          h('button', {className: 'secondary', disabled: saving || !event.history, onClick: async () => { try { await updateEvent({...event, history: null}); } catch (_) {} }}, 'Rimuovi storico')),
        h('p', {className: 'endu-status'}, event.history ? `Storico caricato: ${event.history.mode} · ogni importazione sostituisce solo lo storico di questo evento.` : 'Nessuno storico caricato per questo evento.'),
        h('div', {className: 'panel-heading'}, h('h2', null, 'Conteggi giornalieri effettivi')),
        h('div', {className: 'filters'}, h('label', {className: 'button'}, 'Importa iscrizioni giornaliere CSV', h('input', {hidden: true, type: 'file', accept: '.csv', disabled: saving, onChange: e => importCSV(e.target, true)}))),
        h('p', {className: 'endu-status'}, 'Formato data;iscrizioni, una riga per giorno con le nuove iscrizioni (non il totale cumulato). Questi dati alimentano “Iscrizioni di ieri” senza stime. Le date già presenti vengono aggiornate.'),
        h('details', {className: 'help'}, h('summary', null, 'Formato e criteri del confronto'), h('p', null, 'CSV UTF-8 con intestazioni data;iscrizioni. Date AAAA-MM-GG o GG/MM/AAAA. In modalità settimanale si sommano i valori fino alla settimana più vicina al giorno/mese dell’anno precedente (massimo 3 giorni; in parità la precedente). Per giornalieri e cumulati serve la data esatta. Il 29 febbraio si confronta col 28 febbraio. Includi i periodi a zero e lo stesso insieme di gare conteggiato da Endu.'), h('p', null, 'Il JSON fornisce solo un totale. La stima di ieri richiede rilevazioni entro i primi 5 minuti di due mezzanotti consecutive. Se mancano, il dato è non disponibile. Le cancellazioni possono alterare il saldo. Le rilevazioni passate non possono essere ricostruite retroattivamente.')),
        h('div', {className: 'filters'}, h('a', {className: 'button', href: 'index.php?action=export'}, 'Esporta eventi e storici'), h('label', {className: 'secondary button'}, 'Importa backup eventi', h('input', {hidden: true, type: 'file', accept: '.json', disabled: saving, onChange: e => importBackup(e.target)})), h('button', {className: 'secondary', disabled: saving, onClick: migrateBrowser}, 'Recupera vecchia anteprima')))
    )
  );
}
ReactDOM.createRoot(document.getElementById('root')).render(h(App));
