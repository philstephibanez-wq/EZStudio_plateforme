/* EZS v4.2e0: application soft ping; read-only and independent from hard probes. */
'use strict';
(function($){
    if (!$) return;
    $(function(){
        const names = ['dev','lab','assistant','prod'];
        let busy = false;
        function render(name, item){
            const element = $('#soft-'+name);
            if (!element.length) return;
            if (!item) { element.text('Indisponible'); return; }
            const labels = {
                OK:'OK', KO:'KO', INJOIGNABLE:'Injoignable',
                NON_IMPLEMENTE:'Endpoint absent', NON_CONFORME:'Contrat invalide',
                ERREUR_HTTP:'Erreur HTTP'
            };
            element.text((labels[item.state] || 'Inconnu')+
                (item.http !== null ? ' · HTTP '+item.http : '')+
                (typeof item.elapsed_ms === 'number' ? ' · '+item.elapsed_ms+' ms' : ''));
            element.attr('data-ezs-soft-state',item.state || 'UNKNOWN');
            element.attr('title',item.reason || '');
        }
        function refresh(){
            if (busy || document.hidden) return;
            busy=true;
            $.ajax({url:'/api/v2/infrastructure/web-soft-ping',method:'GET',dataType:'json',cache:false,timeout:10000})
                .done(function(data){
                    if (!data || data.schema!=='ezs.web_soft_ping.v1' || !data.sites) {
                        names.forEach(function(name){render(name,null);});
                        return;
                    }
                    names.forEach(function(name){render(name,data.sites[name]);});
                })
                .fail(function(){names.forEach(function(name){render(name,null);});})
                .always(function(){busy=false;});
        }
        refresh();
        window.setInterval(refresh,30000);
        $('#refresh-dev-health').on('click',refresh);
    });
})(window.jQuery);
