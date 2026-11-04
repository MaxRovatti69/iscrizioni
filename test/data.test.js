import {test} from 'node:test';
import assert from 'node:assert/strict';
import {parseCSV,mergeRows,toCSV} from '../src/data.js';
test('CSV italiano con BOM, importi, virgolette e righe multiple',()=>{
  const rows=parseCSV('\uFEFFnome;cognome;evento;importo;stato\r\nAnna;Rossi;"Trail; Lago";"1.250,50";pagato\r\n"Luca\nMario";Bianchi;Corsa;20;pending');
  assert.equal(rows.length,2);assert.equal(rows[0].amount,1250.5);assert.equal(rows[0].event,'Trail; Lago');assert.equal(rows[1].status,'In attesa');
});
test('rifiuta intestazioni mancanti, importi invalidi e CSV incompleto',()=>{
  for(const csv of ['nome\nAnna','nome;evento;importo\nAnna;Trail;abc','nome;evento\nAnna;"Trail']) assert.throws(()=>parseCSV(csv));
});
test('aggiorna ID nello stesso evento preservando eventi diversi',()=>{
 const a={id:'1',name:'Anna',event:'A',email:''}; const rows=mergeRows([a],[{...a,status:'Pagato'},{...a,event:'B'}]);assert.equal(rows.length,2);assert.equal(rows[0].status,'Pagato');
});
test('export reimportabile e neutralizzazione formule di foglio di calcolo',()=>{
 const row={name:'=SUM(A1)',event:'Trail',email:'a@example.com',date:'',status:'Pagato',amount:25};const csv=toCSV([row]);assert.ok(csv.includes("'=SUM(A1)"));assert.equal(parseCSV(csv)[0].amount,25);
});
