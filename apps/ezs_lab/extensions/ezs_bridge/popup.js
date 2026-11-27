'use strict';
const $=id=>document.getElementById(id);let state=null;
async function run(action,payload){const [tab]=await chrome.tabs.query({active:true,currentWindow:true});if(!tab?.id)throw Error('ONGLET_ABSENT');const origin=new URL(tab.url).origin;const required=['load','restore'].includes(action)?'http://lab.ezstudio.local:8000':'https://chatgpt.com';if(origin!==required)throw Error('ONGLET_ATTENDU : '+required);const [r]=await chrome.scripting.executeScript({target:{tabId:tab.id},func:bridgeOperation,args:[action,payload === undefined ? null : payload]});if(!r?.result?.ok)throw Error(r?.result?.error||'RESULTAT_ABSENT');return r.result;}
function show(){const sel=$('batch');sel.replaceChildren();if(!state)return;state.data.groups.forEach((g,i)=>{const o=document.createElement('option');o.value=i;o.textContent='Lot '+(i+1)+'/'+state.data.groups.length+' — '+g.expected.profiles.length+' chansons — '+g.bytes+' octets'+(state.reports[g.id]?' — capturé':'');sel.append(o);});sel.value=state.index||0;}
async function save(){await chrome.storage.session.set({state});}
function group(){if(!state)throw Error('CHARGER_CAMPAGNE');return state.data.groups[Number($('batch').value)];}
function bind(id,fn){$(id).onclick=async()=>{const bs=[...document.querySelectorAll('button')];bs.forEach(b=>b.disabled=true);try{await fn();}catch(e){$('status').textContent='KO : '+e.message;}finally{bs.forEach(b=>b.disabled=false);}};}
bind('load',async()=>{const r=await run('load');if(r.data.schema!=='ezs.bridge.campaign.v1'||!r.data.groups.length||r.data.groups.some(g=>g.bytes>200000))throw Error('CAMPAGNE_INVALIDE');state={data:r.data,index:0,reports:{}};await save();show();$('status').textContent='CAMPAGNE_OK';});
$('batch').onchange=async()=>{if(state){state.index=Number($('batch').value);await save();}};
bind('open',async()=>{group();await chrome.tabs.create({url:'https://chatgpt.com/'});});
bind('inject',async()=>{const g=group();const r=await run('inject',g.prompt);$('status').textContent='INJECT_OK : '+r.length+' caractères. Vérifier puis envoyer manuellement.';});
bind('capture',async()=>{const g=group();const r=await run('capture',g.expected);validateReport(r.data,g.expected);state.reports[g.id]=r.data;await save();show();$('status').textContent='CAPTURE_OK';});
bind('restore',async()=>{group();if(!Object.keys(state.reports).length)throw Error('CAPTURE_REQUISE');await run('restore',{campaign:state.data.campaign,complete:Object.keys(state.reports).length===state.data.groups.length,reports:Object.values(state.reports)});$('status').textContent='RESTORE_OK : contrôle visuel, aucune écriture SQLite.';});
chrome.storage.session.get('state').then(r=>{state=r.state||null;show();});
