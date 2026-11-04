import {test} from 'node:test';
import assert from 'node:assert/strict';
import {entrantCount} from '../src/endu-data.js';
test('legge il totale Endu incluso zero', () => {
  assert.equal(entrantCount({data:[{count:'3403'}]}),3403);
  assert.equal(entrantCount({data:[{count:'0'}]}),0);
});
test('rifiuta totali invalidi e risposte ambigue', () => {
  for (const value of ['',null,'abc','-1','2.5','9007199254740992']) assert.throws(()=>entrantCount({data:[{count:value}]}));
  for (const payload of [{}, {data:[]}, {data:[{count:'1'},{count:'2'}]}]) assert.throws(()=>entrantCount(payload));
});
