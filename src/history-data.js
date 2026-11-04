export function isoDate(value) {
  let parts;
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) parts = value.split('-').map(Number);
  else if (/^\d{2}\/\d{2}\/\d{4}$/.test(value)) parts = value.split('/').reverse().map(Number);
  else throw new Error('Usa date AAAA-MM-GG o GG/MM/AAAA.');
  const [year, month, day] = parts;
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) throw new Error('Data non valida.');
  return date.toISOString().slice(0,10);
}
export function previousYear(date) {
  const [year, month, day] = isoDate(date).split('-').map(Number);
  const lastDay = new Date(Date.UTC(year - 1, month, 0)).getUTCDate();
  return `${year-1}-${String(month).padStart(2,'0')}-${String(Math.min(day,lastDay)).padStart(2,'0')}`;
}
export function parseHistory(text, mode) {
  if (!['daily','cumulative','weekly'].includes(mode)) throw new Error('Tipo di storico non valido.');
  const lines = text.replace(/^\uFEFF/,'').trim().split(/\r?\n/).filter(line=>line.trim());
  const separator = lines[0]?.includes(';') ? ';' : ',';
  if (lines.shift()?.toLowerCase().trim() !== `data${separator}iscrizioni`) throw new Error('Intestazioni richieste: data;iscrizioni.');
  const seen = new Set();
  const rows = lines.map((line,i)=>{
    const cells = line.split(separator).map(cell=>cell.trim().replace(/^"(.*)"$/,'$1'));
    if (cells.length !== 2) throw new Error(`Riga ${i+2}: servono data e iscrizioni.`);
    const date = isoDate(cells[0]);
    if (seen.has(date)) throw new Error(`Data duplicata: ${date}.`);
    seen.add(date);
    if (!/^\d+$/.test(cells[1]) || !Number.isSafeInteger(Number(cells[1]))) throw new Error(`Riga ${i+2}: numero di iscrizioni non valido.`);
    return {date,count:Number(cells[1])};
  }).sort((a,b)=>a.date.localeCompare(b.date));
  if (!rows.length) throw new Error('Il file non contiene dati.');
  for (let i=1;i<rows.length;i++) {
    if (mode==='cumulative' && rows[i].count<rows[i-1].count) throw new Error('I totali cumulati devono essere non decrescenti.');
  }
  return {mode,rows};
}
export function compareHistory(history, current, date) {
  const target = previousYear(date);
  let cutoff = target;
  if (history.mode === 'weekly') {
    const candidates = history.rows.filter(row=>row.date.startsWith(target.slice(0,4))).sort((a,b)=>a.date.localeCompare(b.date));
    const nearest = candidates.reduce((best,row)=>!best || Math.abs(Date.parse(row.date)-Date.parse(target)) < Math.abs(Date.parse(best.date)-Date.parse(target)) ? row : best,null);
    if (!nearest || Math.abs(Date.parse(nearest.date)-Date.parse(target)) > 3.5*86400000) throw new Error('Nessuna settimana storica vicina alla data di confronto.');
    cutoff = nearest.date;
    for (let i=1;i<candidates.length;i++) if ((Date.parse(candidates[i].date)-Date.parse(candidates[i-1].date))/86400000!==7) throw new Error('Lo storico settimanale ha settimane mancanti.');
  }
  const rows = history.rows.filter(row=>row.date.startsWith(target.slice(0,4)) && row.date<=cutoff);
  // Require a value for the exact comparison day, rather than silently using old data.
  if (!rows.length || rows.at(-1).date !== cutoff) throw new Error(`Manca il dato storico del ${target}.`);
  if (history.mode==='daily') {
    for (let i=1;i<rows.length;i++) {
      if ((Date.parse(rows[i].date)-Date.parse(rows[i-1].date))/86400000!==1) throw new Error('Lo storico giornaliero ha date mancanti: inserisci anche i giorni con zero iscrizioni.');
    }
  }
  const past = history.mode!=='cumulative' ? rows.reduce((sum,row)=>sum+row.count,0) : rows.at(-1).count;
  if (!Number.isSafeInteger(past)) throw new Error('Totale storico troppo grande.');
  return {target,past,delta:current-past,percent:past===0?null:(current-past)/past*100,...(history.mode==='weekly'?{weekDate:cutoff}: {})};
}
