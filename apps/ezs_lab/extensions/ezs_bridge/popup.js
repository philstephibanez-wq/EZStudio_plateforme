'use strict';
const $=id=>document.getElementById(id);let state=null, pendingConfirmation=null;
$('bridge-version').textContent='v'+chrome.runtime.getManifest().version;
async function run(action,payload){const [tab]=await chrome.tabs.query({active:true,currentWindow:true});if(!tab?.id)throw Error('ONGLET_ABSENT');const origin=new URL(tab.url).origin;const required=['load','restore'].includes(action)?'http://lab.ezstudio.local:8000':'https://chatgpt.com';if(origin!==required)throw Error('ONGLET_ATTENDU : '+required);const [r]=await chrome.scripting.executeScript({target:{tabId:tab.id},func:bridgeOperation,args:[action,payload === undefined ? null : payload]});if(!r?.result?.ok)throw Error(r?.result?.error||'RESULTAT_ABSENT');return r.result;}
function show(){const sel=$('batch');sel.replaceChildren();sel.hidden=!state||state.data.groups.length<2;$('restore').disabled=true;if(!state)return;state.data.groups.forEach((g,i)=>{const o=document.createElement('option');o.value=i;o.textContent='Lot '+(i+1)+'/'+state.data.groups.length+' — '+g.expected.profiles.length+' chansons — '+g.bytes+' octets'+(state.reports[g.id]?' — capturé':'');sel.append(o);});sel.value=state.index||0;$('paste-open').hidden=group().expected.format!=='jsonl';$('restore').disabled=!state.reports[group().id];}
function confirmCapture(message){
 const panel=$('confirm-capture');
 $('confirm-message').textContent=message;
 panel.hidden=false;
 return new Promise(resolve=>{pendingConfirmation=resolve;});
}
$('confirm-yes').onclick=()=>finishConfirmation(true);
$('confirm-no').onclick=()=>finishConfirmation(false);
function finishConfirmation(value){
 $('confirm-capture').hidden=true;
 pendingConfirmation?.(value);
 pendingConfirmation=null;
}
async function save(){await chrome.storage.session.set({state});}
function group(){if(!state)throw Error('CHARGER_CAMPAGNE');return state.data.groups[Number($('batch').value)];}
function bind(id,fn){$(id).onclick=async()=>{const bs=[...document.querySelectorAll('button:not([data-confirm-action])')];bs.forEach(b=>b.disabled=true);try{await fn();}catch(e){$('status').textContent='KO : '+e.message;}finally{bs.forEach(b=>b.disabled=false);$('restore').disabled=!state?.reports[group().id];}};}
bind('load',async()=>{const r=await run('load');if(r.data.schema!=='ezs.bridge.campaign.v1'||!r.data.groups.length||r.data.groups.some(g=>g.bytes>200000))throw Error('CAMPAGNE_INVALIDE');const reports=state?.data.campaign===r.data.campaign?state.reports:{};state={data:r.data,index:0,reports};await save();show();$('status').textContent=reports[group().id]?'CAPTURE_OK':'CAMPAGNE_OK';});
$('batch').onchange=async()=>{if(state){state.index=Number($('batch').value);await save();show();}};
bind('open',async()=>{group();await chrome.tabs.create({url:'https://chatgpt.com/'});});
bind('inject',async()=>{const g=group();const r=await run('inject',g.prompt);$('status').textContent='INJECT_OK : '+r.length+' caractères. Vérifier puis envoyer manuellement.';});
bind('capture',async()=>{const g=group();const payload={...g.expected,prompt:g.prompt};let r;
try{r=await run('capture',payload);}catch(error){
 if(g.expected.format!=='jsonl'||!error.message.startsWith('DERNIER_PROMPT_DIFFERENT'))throw error;
 const context=g.prompt.split(/\r?\n/).find(line=>line.trim())?.slice(0,220)||'';
 if(!await confirmCapture('Le prompt ne correspond pas au texte affiché. Associer la dernière réponse visible au run #'+g.expected.run_id+' ?\n\n'+context))return;
 r=await run('capture',{...payload,confirmVisibleReply:true});
}if(g.expected.format==='jsonl'){if(typeof r.data!=='string'||!r.data.trim())throw Error('REPONSE_VIDE');}else validateReport(r.data,g.expected);state.reports[g.id]=r.data;await save();show();$('status').textContent='CAPTURE_OK';});
bind('restore',async()=>{group();if(!state.reports[group().id])throw Error('CAPTURE_REQUISE');if(group().expected.format==='jsonl'){await run('restore',{campaign:state.data.campaign,format:'jsonl',text:state.reports[group().id]});$('status').textContent='JSONL affiché dans PROFILE.';return;}await run('restore',{campaign:state.data.campaign,complete:Object.keys(state.reports).length===state.data.groups.length,reports:Object.values(state.reports)});$('status').textContent='RESTORE_OK : contrôle visuel, aucune écriture SQLite.';});
$('paste-open').onclick=()=>{
 const g=group();
 $('paste-context').textContent='Analyse #'+g.expected.run_id+' · '+g.prompt.split(/\r?\n/).find(line=>line.trim());
 $('paste-panel').hidden=false;
 $('paste-text').focus();
};
bind('paste-save',async()=>{
 const g=group();
 if(g.expected.format!=='jsonl')throw Error('FICHE_INDIVIDUELLE_REQUISE');
 const text=$('paste-text').value.trim().replace(/^```(?:jsonl|json)?[ \t]*\r?\n/i,'').replace(/\r?\n```$/,'');
 const lines=text.split(/\r?\n/).filter(line=>line.trim());
 if(!lines.length||text.length>200000)throw Error('JSONL_VIDE_OU_TROP_LONG');
 const ids=new Set();
 for(const line of lines){
   const item=JSON.parse(line);
   if(!item||typeof item.item_id!=='string'||ids.has(item.item_id)||!Object.hasOwn(item,'valeur')||!Array.isArray(item.sources))
      throw Error('LIGNE_JSONL_INVALIDE');
   ids.add(item.item_id);
 }
 state.reports[g.id]=text;
 await save();
 show();
 $('paste-panel').hidden=true;
 $('paste-text').value='';
 $('status').textContent='Fiche associée. Retourne dans PROFILE et clique 5. Restituer dans LAB.';
});
chrome.storage.session.get('state').then(r=>{state=r.state||null;show();});
