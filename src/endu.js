import {entrantCount} from './endu-data.js';
const container = document.getElementById('endu-counters');
const status = document.getElementById('endu-status');
const button = document.getElementById('endu-refresh');
let lastSuccess = null;
async function refresh() {
  button.disabled = true;
  status.textContent = 'Aggiornamento del numero di iscritti…';
  try {
    const response = await fetch('/api/endu/counters', {cache:'no-store',signal:AbortSignal.timeout(25000)});
    const payload = await response.json();
    const source = payload.sources?.['entrants.json'];
    if (!response.ok || !source || !Object.hasOwn(source, 'data')) throw new Error(source?.error || 'Numero di iscritti non disponibile.');
    const count = entrantCount(source.data);
    const card = document.createElement('article');
    const heading = document.createElement('h3'); heading.textContent = 'Iscritti totali';
    const value = document.createElement('span'); value.textContent = count.toLocaleString('it-IT');
    card.append(heading, value);
    container.replaceChildren(card);
    lastSuccess = new Date(payload.retrievedAt).toLocaleString('it-IT',{timeZone:'Europe/Rome'});
    status.textContent = `Ultima lettura: ${lastSuccess} (ora italiana). Non indica la data di aggiornamento alla fonte.`;
  } catch (error) {
    status.textContent = `${error.name === 'TimeoutError' ? 'Endu non ha risposto in tempo.' : error.message}${lastSuccess ? ` Valore precedente mantenuto, letto il ${lastSuccess}: potrebbe non essere aggiornato.` : ' Nessun dato Endu caricato.'}`;
  } finally { button.disabled = false; }
}
button.addEventListener('click', refresh);
refresh();
