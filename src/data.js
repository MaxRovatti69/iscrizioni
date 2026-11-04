export function parseCSV(text) {
  text = text.replace(/^\uFEFF/, '');
  const first = text.split(/\r?\n/)[0];
  const delimiter = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : ',';
  const rows = []; let row = [], cell = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '"') { if (quoted && text[i + 1] === '"') { cell += '"'; i++; } else quoted = !quoted; }
    else if (c === delimiter && !quoted) { row.push(cell.trim()); cell = ''; }
    else if ((c === '\n' || c === '\r') && !quoted) { if(c === '\r' && text[i + 1] === '\n') i++; row.push(cell.trim()); if(row.some(Boolean)) rows.push(row); row = []; cell = ''; }
    else cell += c;
  }
  if (quoted) throw new Error('CSV non valido: virgolette non chiuse.');
  row.push(cell.trim()); if(row.some(Boolean)) rows.push(row);
  if (rows.length < 2) throw new Error('Il CSV deve contenere intestazioni e almeno una iscrizione.');
  const headers = rows.shift().map(h => h.toLowerCase().trim());
  const column = (...names) => headers.findIndex(h => names.includes(h));
  const cols = { id: column('id','id iscrizione','registration_id'), name: column('partecipante','participant','nome completo'), first: column('nome','first_name','first name'), last: column('cognome','last_name','last name'), event: column('evento','event','event_name'), email: column('email','e-mail'), date: column('data','data iscrizione','date'), status: column('stato','pagamento','status','payment_status'), amount: column('importo','amount') };
  if(cols.event < 0 || (cols.name < 0 && cols.first < 0)) throw new Error('Mancano le colonne partecipante (o nome) ed evento.');
  return rows.map((r,i) => {
    const get = key => r[cols[key]] || '';
    const name = get('name') || [get('first'),get('last')].filter(Boolean).join(' ');
    if(!name || !get('event')) throw new Error(`Riga ${i+2}: partecipante o evento mancante.`);
    let raw = get('amount').replace(/[€\s]/g,'');
    if(raw.includes(',')) raw = raw.replace(/\./g,'').replace(',','.');
    const amount = raw ? Number(raw) : 0;
    if(!Number.isFinite(amount) || amount < 0) throw new Error(`Riga ${i+2}: importo non valido.`);
    const state = get('status').toLowerCase();
    const status = ['pagato','paid','completato','completed'].includes(state) ? 'Pagato' : ['in attesa','pending','non pagato','unpaid'].includes(state) ? 'In attesa' : 'Non specificato';
    return {id:get('id'), name, event:get('event'), email:get('email'), date:get('date'),status,amount};
  });
}
export function mergeRows(current, incoming) {
  const key = r => JSON.stringify([r.event, r.id || [r.name,r.email]]);
  return [...new Map([...current,...incoming].map(r => [key(r),r])).values()];
}
export function toCSV(rows) {
  const quote = value => '"' + String(value).replace(/^[=+@\-\t\r]/, "'$&").replaceAll('"','""') + '"';
  return '\uFEFF' + [['partecipante','evento','email','data','stato','importo'], ...rows.map(r=>[r.name,r.event,r.email,r.date,r.status,r.amount])].map(r=>r.map(quote).join(';')).join('\r\n');
}
