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
    if(action==='capture'&&chat&&payload.format==='jsonl'){
      const messages=[...document.querySelectorAll('[data-message-author-role]')].filter(node=>node.getClientRects().length);
      const last=messages.findLastIndex(node=>node.getAttribute('data-message-author-role')==='user');
      const comparable=text=>text.normalize('NFC').replace(/[\s\u200b\ufeff]+/gu,' ').trim();
      const user=messages[last];
      const prompt=comparable(payload.prompt);
      const rendered=user&&[user.innerText,user.textContent];
      if(!payload.confirmVisibleReply && !rendered?.some(text=>typeof text==='string'&&comparable(text)===prompt))
        throw Error('DERNIER_PROMPT_DIFFERENT : le dernier message doit correspondre au prompt charge depuis PROFILE.');
      if(document.querySelector('[data-testid="stop-button"]'))throw Error('REPONSE_EN_COURS');
      const replies=messages.slice(last+1).filter(node=>node.getAttribute('data-message-author-role')==='assistant');
      const userTurn=user?.closest('article,[data-testid^="conversation-turn-"]');
      const turns=document.querySelectorAll('article,[data-testid^="conversation-turn-"]');
      if(userTurn) for(const turn of turns){
        if(turn.getClientRects().length && !turn.contains(userTurn) && !userTurn.contains(turn) &&
           userTurn.compareDocumentPosition(turn)&Node.DOCUMENT_POSITION_FOLLOWING) replies.push(turn);
      }
      const renderers=[...document.querySelectorAll('[data-dil-response]')].filter(node=>
        node.getClientRects().length && !node.closest('[data-message-author-role="user"]'));
      const scoped=user ? renderers.filter(node=>
        !user.contains(node) && (user.compareDocumentPosition(node)&Node.DOCUMENT_POSITION_FOLLOWING)) : [];
      if(scoped.length) replies.push(...scoped);
      else if(payload.confirmVisibleReply && !user && renderers.length) replies.push(renderers.at(-1));
      const found=new Set();
      for(const reply of replies){
        const candidates=[...reply.querySelectorAll('pre code')].map(node=>node.textContent);
        candidates.push(read(reply),reply.textContent||'');
        for(const candidate of candidates){
          const lines=[];
          let start=-1, depth=0, quoted=false, escaped=false;
          for(let i=0;i<candidate.length;i++){
            const char=candidate[i];
            if(start<0){if(char==='{'){start=i;depth=1;}continue;}
            if(quoted){
              if(escaped)escaped=false;
              else if(char==='\\')escaped=true;
              else if(char==='"')quoted=false;
            }else if(char==='"')quoted=true;
            else if(char==='{')depth++;
            else if(char==='}'&&--depth===0){
              try{
                const item=JSON.parse(candidate.slice(start,i+1));
                if(typeof item.item_id==='string' && 'valeur' in item && Array.isArray(item.sources))
                  lines.push(JSON.stringify(item));
              }catch{}
              start=-1;
            }
          }
          if(lines.length && new Set(lines.map(line=>JSON.parse(line).item_id)).size===lines.length) found.add(lines.join('\n'));
        }
      }
      const complete=[...found].filter(text=>text.split('\n').length===11);
      if(complete.length===1)return {ok:true,data:complete[0]};
      if(!complete.length&&found.size===1)return {ok:true,data:[...found][0]};
      throw Error(found.size ? 'PLUSIEURS_FICHES_JSONL' : 'AUCUNE_FICHE_JSONL_DETECTEE');
    }
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
    if(action==='restore'&&local&&payload.format==='jsonl'){
      const field=document.getElementById('ezs-bridge-campaign');
      const campaign=field&&JSON.parse(field.value);
      if(!campaign||campaign.campaign!==payload.campaign)throw Error('CAMPAGNE_DIFFERENTE');
      const expected=campaign.groups[0]?.expected;
      const run=document.querySelector('[data-profile-chatgpt]')?.dataset.run;
      if(expected?.format!=='jsonl'||String(expected.run_id)!==run)throw Error('ANALYSE_DIFFERENTE');
      const box=document.getElementById('profile-chatgpt-result');
      const output=box?.querySelector('[data-report-text]');
      if(!output||typeof payload.text!=='string'||!payload.text.trim())throw Error('JSONL_INDISPONIBLE');
      output.value=payload.text;
      box.querySelector('[data-report-label]').textContent='Analyse #'+run+' / Prompt #'+expected.prompt_revision_id+' / Bridge Brave';
      box.querySelector('[data-copy-status]').textContent='';
      box.querySelector('[data-copy-jsonl]').disabled=false;
      box.scrollIntoView({block:'nearest'});
      return {ok:true};
    }
    if(action==='restore'&&local){const field=document.querySelector('#ezs-bridge-campaign');if(!field||JSON.parse(field.value).campaign!==payload.campaign)throw Error('CAMPAGNE_DIFFERENTE');let box=document.getElementById('ezs-bridge-review');if(box&&box.tagName!=='DIALOG'){box.remove();box=null;}if(!box){box=document.createElement('dialog');box.id='ezs-bridge-review';box.setAttribute('aria-label','Rapport d’expertise');box.style.maxWidth='min(70rem, 90vw)';box.style.maxHeight='85vh';document.body.append(box);}box.textContent='Rapport à contrôler — aucune importation en base';const pre=document.createElement('pre');pre.style.whiteSpace='pre-wrap';pre.textContent=JSON.stringify(payload,null,2);const close=document.createElement('button');close.type='button';close.textContent='Fermer';close.addEventListener('click',()=>box.close());box.prepend(close);box.append(pre);if(!box.open)box.showModal();return {ok:true};}
    throw new Error('ACTION_NON_AUTORISEE');
  }catch(e){return {ok:false,error:String(e.message||e)};}
}
