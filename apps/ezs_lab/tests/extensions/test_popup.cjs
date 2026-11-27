const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
const elements=new Map();const $=id=>{if(!elements.has(id))elements.set(id,{value:'0',textContent:'',replaceChildren(){},append(){}});return elements.get(id)};
const g={id:'lot1',bytes:100,prompt:'prompt',expected:{batch_id:'lot1',profiles:[{audio_sha256:'a',run_id:1,prompt_revision_id:1}],item_ids:['1'],verdicts:['BON']}};
const report={batch_id:'lot1',profiles:[{...g.expected.profiles[0],items:[{item_id:'1',verdict:'BON',justification:'test',sources:['https://example.com']}]}]};
let origin='http://lab.ezstudio.local:8000',stored={},calls=[],opened=false;
const context=vm.createContext({URL,document:{getElementById:$,createElement:()=>({}),querySelectorAll:()=>[]},chrome:{tabs:{query:async()=>[{id:1,url:origin}],create:async x=>{assert.equal(x.url,'https://chatgpt.com/');opened=true;}},storage:{session:{get:async()=>stored,set:async x=>{stored=x;}}},scripting:{executeScript:async x=>{assert.equal(x.args.length,2);assert.notEqual(x.args[1],undefined);const [action,payload]=JSON.parse(JSON.stringify(x.args));calls.push(action);switch(action){case 'load':assert.equal(payload,null);return [{result:{ok:true,data:{schema:'ezs.bridge.campaign.v1',campaign:'campaign1',groups:[g]}}}];case 'inject':assert.equal(payload,g.prompt);return [{result:{ok:true,length:6}}];case 'capture':assert.deepEqual(payload,g.expected);return [{result:{ok:true,data:report}}];case 'restore':assert.deepEqual(payload,{campaign:'campaign1',complete:true,reports:[report]});return [{result:{ok:true}}];default:throw Error(action);}}}}});
for(const f of ['bridge.js','contract.js','popup.js'])vm.runInContext(fs.readFileSync(require('node:path').join(__dirname,'../../extensions/ezs_bridge',f),'utf8'),context);
await new Promise(r=>setImmediate(r));
await $('load').onclick();assert.equal($('status').textContent,'CAMPAGNE_OK');console.log('LOAD_OK');
await $('open').onclick();assert(opened);console.log('OPEN_OK');
origin='https://chatgpt.com';await $('inject').onclick();assert.match($('status').textContent,/^INJECT_OK/);console.log('INJECT_OK');
await $('capture').onclick();assert.equal($('status').textContent,'CAPTURE_OK');console.log('CAPTURE_OK');
await $('restore').onclick();assert.match($('status').textContent,/ONGLET_ATTENDU/);assert.equal(calls.length,3);console.log('WRONG_ORIGIN_REJECTED');
origin='http://lab.ezstudio.local:8000';await $('restore').onclick();assert.match($('status').textContent,/^RESTORE_OK/);assert.deepEqual(calls,['load','inject','capture','restore']);console.log('RESTORE_OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
