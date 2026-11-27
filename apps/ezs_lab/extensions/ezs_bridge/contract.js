'use strict';
function validateReport(d,e){
 if(!d||d.batch_id!==e.batch_id||!Array.isArray(d.profiles)||d.profiles.length!==e.profiles.length)throw Error('RAPPORT_INCOMPLET');
 const key=x=>JSON.stringify([x.audio_sha256,x.run_id,x.prompt_revision_id]);const expected=new Set(e.profiles.map(key));
 for(const p of d.profiles){if(!expected.delete(key(p))||!Array.isArray(p.items)||p.items.length!==e.item_ids.length)throw Error('PROFILE_RAPPORT_INVALIDE');const ids=new Set(e.item_ids);for(const i of p.items){if(!ids.delete(i.item_id)||!e.verdicts.includes(i.verdict)||typeof i.justification!=='string'||!i.justification.trim()||!Array.isArray(i.sources)||i.sources.some(u=>typeof u!=='string'||!/^https?:\/\//i.test(u))||(i.verdict!=='NON TROUVÉ'&&!i.sources.length))throw Error('ITEM_RAPPORT_INVALIDE');}}
 return true;
}
if(typeof module!=='undefined')module.exports={validateReport};
