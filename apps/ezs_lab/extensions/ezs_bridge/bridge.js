'use strict';
async function bridgeOperation(action,payload) {
  try {
    const local=location.origin==='http://lab.ezstudio.local:8000', chat=location.origin==='https://chatgpt.com';
    if(!local&&!chat) throw new Error('ORIGINE_NON_AUTORISEE');
    const selectors=local?['#composer']:['#prompt-textarea','[contenteditable="true"][data-placeholder]','form [contenteditable="true"][role="textbox"]','form .ProseMirror[contenteditable="true"]','textarea#prompt-textarea'];
    const candidates=[...new Set(selectors.flatMap(s=>Array.from(document.querySelectorAll(s))))].filter(e=>e.getClientRects().length && (e.isContentEditable||e.tagName==='TEXTAREA'));
    const el=candidates[0];
    const read=e=>e.tagName==='TEXTAREA'?e.value:(e.innerText||e.textContent||'');
    const norm=s=>s.replace(/\r/g,'').replace(/\u00a0/g,' ').trim();
    if(action==='diagnose') return {ok:true,origin:location.origin,candidates:candidates.map(e=>({tag:e.tagName,id:e.id,editable:e.isContentEditable,role:e.getAttribute('role'),length:read(e).length})),focused:document.hasFocus()};
    if(action==='inject') {
      if(!el) throw new Error('EDITEUR_ABSENT');
      if(candidates.length>1) throw new Error('EDITEURS_MULTIPLES : diagnostic requis');
      if(norm(read(el))) throw new Error('EDITEUR_NON_VIDE : aucune modification');
      el.focus();
      if(el.tagName==='TEXTAREA') {el.setSelectionRange(0,0);} else {const r=document.createRange();r.selectNodeContents(el);r.collapse(false);const s=window.getSelection();s.removeAllRanges();s.addRange(r);}
      let native=false;try{native=document.execCommand('insertText',false,payload);}catch{}
      await new Promise(r=>setTimeout(r,150));
      if(norm(read(el))!==norm(payload)) {
        if(norm(read(el))) throw new Error('INSERTION_PARTIELLE : longueur '+read(el).length+' ; ne pas envoyer');
        if(el.tagName==='TEXTAREA') {Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value').set.call(el,payload);el.dispatchEvent(new Event('input',{bubbles:true}));}
        else {const dt=new DataTransfer();dt.setData('text/plain',payload);el.dispatchEvent(new ClipboardEvent('paste',{clipboardData:dt,bubbles:true,cancelable:true}));}
        await new Promise(r=>setTimeout(r,250));
      }
      if(norm(read(el))!==norm(payload)) throw new Error('INSERTION_NON_CONFIRMEE : native='+native+' longueur='+read(el).length+' attendue='+payload.length);
      return {ok:true,length:payload.length};
    }
    if(action==='load'&&local){const field=document.querySelector('#ezs-bridge-campaign');if(!field)throw Error('PREPARER_DANS_LAB');return {ok:true,data:JSON.parse(field.value)};}
    if(action==='capture'&&chat){
      const selector='p[data-d-component="text"][data-d-text-flow], [data-message-author-role="assistant"], [data-testid="assistant-message"]';
      const found=new Map();
      for(const node of document.querySelectorAll(selector)){
        if(!node.getClientRects().length||node.closest('[contenteditable="true"], [data-message-author-role="user"], [data-role="user"]'))continue;
        for(const raw of [node.innerText||'',node.textContent||'']){try{const d=JSON.parse(raw.trim().replace(/^```(?:json)?\s*/i,'').replace(/\s*```$/,''));if(d.batch_id===payload.batch_id&&Array.isArray(d.profiles))found.set(JSON.stringify(d),d);}catch{}}
      }
      if(found.size!==1)throw Error('CAPTURE_ABSENTE_OU_AMBIGUE : '+found.size);
      return {ok:true,data:[...found.values()][0]};
    }
    if(action==='restore'&&local){const field=document.querySelector('#ezs-bridge-campaign');if(!field||JSON.parse(field.value).campaign!==payload.campaign)throw Error('CAMPAGNE_DIFFERENTE');let box=document.getElementById('ezs-bridge-review');if(!box){box=document.createElement('section');box.id='ezs-bridge-review';document.querySelector('.wrap')?.prepend(box);if(!box.isConnected)document.body.prepend(box);}box.textContent='Rapport à contrôler — aucune importation en base';const pre=document.createElement('pre');pre.style.whiteSpace='pre-wrap';pre.textContent=JSON.stringify(payload,null,2);box.append(pre);return {ok:true};}
    throw new Error('ACTION_NON_AUTORISEE');
  }catch(e){return {ok:false,error:String(e.message||e)};}
}
