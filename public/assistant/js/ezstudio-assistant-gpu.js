/* XPS GPU live monitor, using the identical LAB telemetry and panel DOM.
   No dependency on remote jQuery or LAB's other page controllers. */
(function(){
'use strict';
function init(){
 var root=document.getElementById('ezs-performance-panel');
 if(!root)return;
 window.ezsGpuMonitorBooted=true;
 var url=root.getAttribute('data-telemetry-url');
 var history=[], MAX=60, timer=null, stopped=false;
 function node(a){return root.querySelector('['+a+']');}
 function put(a,v){var n=node(a);if(n)n.textContent=v;}
 function fmt(v,d){return v===null||v===undefined||v===''||!Number.isFinite(Number(v))?'—':Number(v).toFixed(d||0);}
 function mark(s,ok){var n=node('data-perf-status');if(n){n.textContent=s;n.classList.toggle('perf-live-on',!!ok);}}
 function plot(key,w,h,lo,hi){
   var line=node('data-perf-line="'+key+'"'),area=node('data-perf-area="'+key+'"');
   if(!line||!area)return;
   if(history.length<2){line.setAttribute('points','');area.setAttribute('points','');return;}
   var span=Math.max(.0001,hi-lo);
   var points=history.map(function(r,i){
     var value=Math.min(hi,Math.max(lo,Number(r[key])||0));
     return (i/(MAX-1)*w).toFixed(1)+','+(h-2-((value-lo)/span)*(h-4)).toFixed(1);
   }).join(' ');
   line.setAttribute('points',points);
   area.setAttribute('points','0,'+h+' '+points+' '+((history.length-1)/(MAX-1)*w).toFixed(1)+','+h);
 }
 function render(data){
   if(!data||data.available!==true||!data.gpu){mark('Télémétrie indisponible · réponse invalide',false);return;}
   var g=data.gpu, memory=fmt(g.memory_used_mib)+' / '+fmt(g.memory_total_mib)+' MiB';
   put('data-perf-name',g.name||'NVIDIA');
   put('data-perf-util',fmt(g.utilization_percent)+' %');
   put('data-perf-memory',fmt(g.memory_percent)+' %');
   put('data-perf-memory-detail',memory);
   put('data-perf-power',fmt(g.power_w,1)+' W');
   put('data-perf-power-detail','limite '+fmt(g.power_limit_w,1)+' W');
   put('data-perf-temp',fmt(g.temperature_c)+' °C');
   put('data-perf-temp-detail','20–100 °C');
   put('data-perf-clock',fmt(g.sm_clock_mhz)+' MHz');
   put('data-perf-clock-detail',g.pstate||'—');
   put('data-stat-util',fmt(g.utilization_percent)+' %');
   put('data-stat-memory',memory);
   put('data-stat-power',fmt(g.power_w,1)+' / '+fmt(g.power_limit_w,1)+' W');
   put('data-stat-temp',fmt(g.temperature_c)+' °C');
   put('data-stat-clock',fmt(g.sm_clock_mhz)+' MHz');
   put('data-stat-state',g.pstate||'—');
   history.push({util:g.utilization_percent,memory:g.memory_percent,power:g.power_w,temp:g.temperature_c,clock:g.sm_clock_mhz});
   history=history.slice(-MAX);
   plot('util',360,120,0,100);plot('memory',174,62,0,100);
   plot('power',174,62,0,Math.max(1,Number(g.power_limit_w)||Number(g.power_w)||1));
   plot('temp',174,62,20,100);
   plot('clock',174,62,0,Math.max(300,...history.map(function(x){return Number(x.clock)||0;}))*1.05);
   mark('Télémétrie active',true);
 }
 function tick(){
   if(stopped)return;
   if(document.hidden){timer=setTimeout(tick,2000);return;}
   fetch(url,{method:'GET',cache:'no-store',headers:{'Accept':'application/json'}})
     .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
     .then(render)
     .catch(function(e){mark('Télémétrie indisponible · '+String(e.message||e).slice(0,90),false);})
     .finally(function(){if(!stopped)timer=setTimeout(tick,2000);});
 }
 window.addEventListener('pagehide',function(){stopped=true;clearTimeout(timer);});
 document.addEventListener('visibilitychange',function(){if(!document.hidden&&!stopped){clearTimeout(timer);tick();}});
 if(!url){mark('Route télémétrie absente',false);return;}
 mark('Connexion télémétrie…',false);
 tick();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
