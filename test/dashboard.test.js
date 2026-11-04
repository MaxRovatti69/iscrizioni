import {test} from 'node:test';
import assert from 'node:assert/strict';

test('crea una riga senza JSON, importa storico isolato e lo conserva senza richieste Endu aggiuntive',async t=>{
  class Element {
    constructor(){this.children=[];this.listeners={};this.value='';this.textContent='';this.dataset={};}
    append(...children){this.children.push(...children);}
    replaceChildren(...children){this.children=children;}
    addEventListener(name,callback){this.listeners[name]=callback;}
  }
  const nodes=new Map(), storage=new Map(), requests=[];
  const globals=['document','localStorage','fetch','setTimeout','clearTimeout'];
  const previous=Object.fromEntries(globals.map(k=>[k,globalThis[k]]));
  t.after(()=>{for(const k of globals){if(previous[k]===undefined)delete globalThis[k];else globalThis[k]=previous[k];}});
  globalThis.document={hidden:false,createElement(){return new Element();},addEventListener(){},getElementById(id){if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);}};
  globalThis.localStorage={getItem:key=>storage.get(key)??null,setItem:(key,value)=>storage.set(key,value)};
  globalThis.setTimeout=()=>1;globalThis.clearTimeout=()=>{};
  globalThis.fetch=async url=>{requests.push(url);return {ok:true,json:async()=>({retrievedAt:'2026-11-04T12:00:00Z',sources:{'entrants.json':{data:{data:[{count:'3410'}]}}}})};};
  await import('../src/dashboard.js');
  await new Promise(resolve=>setImmediate(resolve));
  const get=id=>document.getElementById(id);
  get('event-new-name').value='Nuova gara 2027';
  get('event-create').listeners.submit({preventDefault(){}});
  assert.equal(get('event-rows').children.length,2);
  assert.match(get('event-rows').children[1].children[5].textContent,/Iscrizioni non ancora aperte/);
  get('history-mode').value='weekly';
  await get('history-file').listeners.change({target:{files:[{size:100,text:async()=> 'data;iscrizioni\n2025-11-02;100'}],value:'file.csv'}});
  const saved=JSON.parse(storage.get('iscrizioni-events-v1'));
  assert.equal(saved[0].history,null);
  assert.equal(saved[1].name,'Nuova gara 2027');
  assert.equal(saved[1].url,'');
  assert.equal(saved[1].history.mode,'weekly');
  assert.match(get('event-rows').children[1].children[2].textContent,/100 alla settimana/);
  assert.equal(get('event-rows').children[1].children[3].textContent,'—');
  assert.equal(requests.length,1);
});
