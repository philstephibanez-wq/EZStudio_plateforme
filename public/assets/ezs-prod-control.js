(function($){
  if (!$) return;
  $(function(){
    const $status=$('#prod-control-status'),$mode=$('#prod-maintenance'),$edge=$('#prod-caddy');
    const $maintenance=$('#prod-maintenance-button'),$normal=$('#prod-normal-button');
    const modalEl=document.getElementById('prod-action-modal');
    const modal=modalEl && window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;
    const $title=$('#prod-action-modal-title'),$description=$('#prod-action-modal-description');
    const $error=$('#prod-action-modal-error'),$confirm=$('#prod-action-confirm');
    let token='',busy=false,pending=null,capabilities={maintenance:false,normal:false};
    let lastGood=null;
    const $diagnostic=$('#prod-refresh-diagnostic');
    const $prodCard=$('#ezs-prod-card'), $maintenanceBadge=$('#prod-maintenance-badge'), $banner=$('#prod-maintenance-banner');
    function showMaintenance(value){
      const active=value===true;
      $prodCard.toggleClass('ezs-prod-maintenance-active',active);
      $maintenanceBadge.toggleClass('d-none',!active);
      $banner.toggleClass('d-none',!active);
      $maintenance.toggleClass('ezs-prod-maintenance-pulse btn-warning',active)
        .toggleClass('btn-outline-warning',!active);
      if(value===null){
        $maintenanceBadge.removeClass('d-none').text('MAINTENANCE INDÉTERMINÉE');
        $banner.removeClass('d-none').text('État de maintenance PROD indisponible');
      } else {
        $maintenanceBadge.text('⚠ MAINTENANCE ACTIVE');
        $banner.text('⚠ PROD EN MAINTENANCE — accès public suspendu');
      }
    }
    function observation(text){if($diagnostic.length)$diagnostic.text(text);}
    function setButtons(){
      $maintenance.prop('disabled',busy||!capabilities.maintenance||!modal);
      $normal.prop('disabled',busy||!capabilities.normal||!modal);
    }
    function setStatus(label, level){
      $status.text(label).removeClass('text-bg-secondary text-bg-success text-bg-warning text-bg-danger')
        .addClass('text-bg-'+level);
    }
    function refreshProd(){
      const start=performance.now();
      $.ajax({url:'/api/v2/infrastructure/prod-control',dataType:'json',cache:false,timeout:5000})
       .done(function(d){
         if(d.schema!=='ezs.prod_control.v1') return;
         token=d.csrf||'';
         lastGood=new Date();
         const x=d.timing_ms||{};
         observation('API '+Math.round(performance.now()-start)+' ms · serveur '+(x.total??'?')+' ms · gateway '+(x.gateway??'?')+' ms · backend '+(x.backend??'?')+' ms · validé '+lastGood.toLocaleTimeString('fr-FR'));
         $mode.text(d.maintenance===true?'Activée':d.maintenance===false?'Mode normal':'Indéterminée');
         showMaintenance(typeof d.maintenance==='boolean'?d.maintenance:null);
         $edge.text(d.caddy_listening?'Écoute 8501':'Arrêté');
         // Same user-facing service vocabulary as DEV, LAB and Assistant.
         // Maintenance is a separate operational mode, not the service-health badge.
         if (d.maintenance===true && d.caddy_listening) {
           setStatus('Maintenance','warning');
         } else if (d.gateway_online === true && d.backend_ready === true) {
           setStatus('En ligne','success');
         } else if (d.gateway_online === false || (d.gateway_online === true && d.backend_ready === false)) {
           setStatus('En erreur','danger');
         } else {
           setStatus('À vérifier','warning');
         }
         capabilities={maintenance:!!d.actions.maintenance,normal:!!d.actions.normal};
         $normal.attr('title',d.note||'');
         setButtons();
       }).fail(function(xhr,status,error){
         const elapsed=Math.round(performance.now()-start);
         const code=xhr.status||0;
         const info='PROD AJAX '+(status||'erreur')+' · HTTP '+code+' · '+elapsed+' ms'+(error?' · '+String(error).slice(0,70):'');
         observation(info+(lastGood?' · dernier état valide '+lastGood.toLocaleTimeString('fr-FR'):''));
         // Never authorize an action from stale information.
         capabilities={maintenance:false,normal:false};setButtons();
         showMaintenance(null);
         // Timeout is unknown health, not success and not a confirmed outage.
         setStatus('À vérifier','warning');
       });
    }
    function ask(action){
      if(busy||!token||!modal||!capabilities[action]) return;
      pending=action;
      const normal=action==='normal';
      $title.text(normal?'Passer EZScore PROD en mode normal':'Activer la maintenance PROD');
      $description.text(normal?
        'Désactiver la maintenance et rendre EZScore PROD accessible sur Internet ? Vérifiez ensuite la connexion Google SSO.':
        'Activer la maintenance publique d’EZScore PROD ? Les utilisateurs ne pourront plus accéder à l’application.');
      $confirm.text(normal?'Activer le mode normal':'Activer la maintenance')
        .toggleClass('btn-success',normal).toggleClass('btn-warning',!normal);
      $error.prop('hidden',true).text('');
      modal.show();
    }
    function execute(){
      if(busy||!pending||!token||!capabilities[pending]) return;
      const action=pending;
      busy=true;setButtons();$confirm.prop('disabled',true);
      $.ajax({
        url:'/api/v2/infrastructure/prod-control/'+action,
        method:'POST',data:{confirmation:action==='normal'?'NORMAL':'MAINTENANCE'},
        headers:{'X-EZS-CSRF':token},timeout:30000
      }).done(function(d){
        setStatus('À vérifier','warning'); // Refresh will attest the new state.
        modal.hide();
      }).fail(function(xhr){
        const d=xhr.responseJSON||{};
        $error.text('Commande refusée : '+(d.error||'échec technique')).prop('hidden',false);
      }).always(function(){
        busy=false;$confirm.prop('disabled',false);refreshProd();
      });
    }
    $maintenance.on('click',function(){ask('maintenance');});
    $normal.on('click',function(){ask('normal');});
    $confirm.on('click',execute);
    $(modalEl).on('hidden.bs.modal',function(){pending=null;$error.prop('hidden',true).text('');});
    refreshProd();
    window.setInterval(function(){if(!document.hidden&&!busy)refreshProd();},15000);
    $('#refresh-dev-health').on('click',refreshProd);
  });
})(window.jQuery);
