/* R9 DEV only: switch Caddy maintenance route; no site start/stop. */
'use strict';
(function($){
    if (!$) return;
    $(function(){
        const normal=$('#dev-normal-button');
        const maintenance=$('#dev-maintenance-button');
        const label=$('#dev-web-mode-label');
        // Do not reuse PROD modal: dedicated dialog prevents collision with PROD control.
        const dialog=document.getElementById('dev-mode-modal');
        const ui=dialog && window.bootstrap ? new window.bootstrap.Modal(dialog) : null;
        const confirm=$('#dev-mode-confirm');
        const error=$('#dev-mode-error');
        let csrf='',mode=null,action=null,busy=false;
        function renderMode(value){
            const active=value==='MAINTENANCE';
            $('#dev-maintenance-badge').toggleClass('d-none',!active);
            $('#dev-maintenance-banner').toggleClass('d-none',!active);
            $('#dev-mode-card').toggleClass('ezs-prod-maintenance-active',active);
            $('#dev-web-mode-detail').text(value==='MAINTENANCE'?'Maintenance':value==='NORMAL'?'Normal':'Indéterminé');
        }
        function buttons(){
            normal.prop('disabled',busy || !csrf || !ui || mode !== 'MAINTENANCE');
            maintenance.prop('disabled',busy || !csrf || !ui || mode !== 'NORMAL');
        }
        function refresh(){
            $.ajax({url:'/api/v2/infrastructure/dev-web-mode',dataType:'json',timeout:5000,cache:false})
                .done(function(data){
                    if (data.schema !== 'ezs.dev_web_mode.v1') throw Error('INVALID_DEV_WEB_MODE');
                    mode=data.mode; csrf=data.csrf||'';
                    label.text(mode==='MAINTENANCE'?'Maintenance':'Normal');
                    renderMode(mode);
                    buttons();
                }).fail(function(){ mode=null;csrf='';label.text('Indéterminé');renderMode(null);buttons(); });
        }
        function ask(next){
            if (busy || !csrf || !ui || (next==='maintenance' && mode!=='NORMAL') || (next==='normal' && mode!=='MAINTENANCE')) return;
            action=next; error.prop('hidden',true).text('');
            $('#dev-mode-description').text(next==='maintenance' ? 'Afficher la page Maintenance sur EZScore DEV ? PROD et les autres sites restent inchangés.' : 'Rétablir la page normale EZScore DEV ?');
            ui.show();
        }
        function execute(){
            if (busy || !action || !csrf) return;
            busy=true; buttons();
            $.ajax({url:'/api/v2/infrastructure/dev-web-mode/'+action,method:'POST',data:{confirmation:action.toUpperCase()+'_DEV'},headers:{'X-EZS-CSRF':csrf},timeout:7000})
                .done(function(){ui.hide();refresh();})
                .fail(function(xhr){error.text((xhr.responseJSON&&xhr.responseJSON.error)||'Changement de mode refusé').prop('hidden',false);})
                .always(function(){busy=false;buttons();});
        }
        normal.on('click',function(){ask('normal');});
        maintenance.on('click',function(){ask('maintenance');});
        confirm.on('click',execute);
        $(dialog).on('hidden.bs.modal',function(){action=null;error.prop('hidden',true).text('');});
        refresh();
        $('#refresh-dev-health').on('click',refresh);
    });
})(window.jQuery);
