import {test} from 'node:test';
import assert from 'node:assert/strict';
import {parseHistory,compareHistory,previousYear} from '../src/history-data.js';
test('somma i giornalieri fino al giorno corrispondente, escludendo giorni successivi',()=>{
  const history=parseHistory('data;iscrizioni\n03/11/2025;100\n04/11/2025;50\n05/11/2025;60','daily');
  assert.deepEqual(compareHistory(history,180,'2026-11-04'),{target:'2025-11-04',past:150,delta:30,percent:20});
});
test('usa il cumulato esatto senza sommare gli snapshot',()=>{
  const history=parseHistory('data,iscrizioni\n2025-11-03,100\n2025-11-04,150','cumulative');
  assert.equal(compareHistory(history,120,'2026-11-04').percent,-20);
});
test('gestisce zero e bisestile',()=>{
  assert.equal(previousYear('2024-02-29'),'2023-02-28');
  assert.equal(compareHistory(parseHistory('data;iscrizioni\n2025-11-04;0','cumulative'),10,'2026-11-04').percent,null);
});
test('rifiuta date impossibili, duplicati e cumulati decrescenti',()=>{
  for(const text of ['data;iscrizioni\n2025-02-29;1','data;iscrizioni\n2025-11-04;1\n2025-11-04;2','data;iscrizioni\n2025-11-03;10\n2025-11-04;5']) assert.throws(()=>parseHistory(text,'cumulative'));
});
test('non usa dati vecchi o giornalieri incompleti come confronto valido',()=>{
  assert.throws(()=>compareHistory(parseHistory('data;iscrizioni\n2025-11-03;10','daily'),10,'2026-11-04'));
  assert.throws(()=>compareHistory(parseHistory('data;iscrizioni\n2025-11-02;10\n2025-11-04;5','daily'),10,'2026-11-04'));
});
