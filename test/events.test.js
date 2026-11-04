import {test} from 'node:test';
import assert from 'node:assert/strict';
import {validateEvent,PISA_URL} from '../src/events-data.js';
test('consente eventi senza JSON e valida il collegamento quando disponibile',()=>{
  assert.deepEqual(validateEvent(' Pisa 2027 ',''),{name:'Pisa 2027',url:''});
  assert.equal(validateEvent('Pisa',PISA_URL).url,PISA_URL);
  for(const url of ['https://example.com/entrants.json',PISA_URL+'?token=x',PISA_URL.replace('entrants.json','counters.json')])assert.throws(()=>validateEvent('Pisa',url));
  assert.throws(()=>validateEvent('',''));
});
