/* EZS web-only hard pings. Read-only; never controls any site or PID. */
'use strict';
(function($){
    if (!$) return;
    $(function(){
        const names=['dev','lab','assistant'];
        const refreshButton=$('#refresh-dev-health');
        const feedback=$('#dev-health-feedback');
        let busy=false;
        function state(site,data){
            const ok=data && data.state==='OK';
            const maintenance=data && data.state==='MAINTENANCE';
            const $card=$('#'+site+'-title').closest('.ezs-server-card');
            const $state=$('#state-'+site);
            $state.text(ok?'EN LIGNE':maintenance?'MAINTENANCE':'HORS LIGNE')
                .removeClass('text-bg-secondary text-bg-success text-bg-danger text-bg-warning')
                .addClass('text-bg-'+(ok?'success':maintenance?'warning':'danger'));
            $card.attr('data-ezs-state',data ? data.state : 'INCONNU');
            $card.attr('data-ezs-probe',data?'hard_tcp_http':'unavailable');
            const publicPort={dev:'8502',lab:'8702',assistant:'8712'}[site];
            const publicOpen=Boolean(data && data.ports && data.ports[publicPort]);
            $('#tcp-'+site).text(publicOpen?'Ouvert':'Fermé');
            $('#http-'+site).text(data && data.http && data.http.status!==null?String(data.http.status):'Sans réponse');
            $('#tech-http-'+site).text(data && data.http && data.http.status!==null?String(data.http.status):'Sans réponse');
            const backend={dev:'8602',lab:'8701',assistant:'8713'}[site];
            const b=Boolean(data && data.ports && data.ports[backend]);
            if(site==='dev'){
                $('#dev-backend').text(b?'Port TCP ouvert':'Port TCP fermé');
                $('#dev-backend-ownership').text('Ping hard TCP/HTTP uniquement');
            } else if(site==='lab') $('#lab-backend').text(b?'Port TCP ouvert':'Port TCP fermé');
        }
        function refresh(){
            if(busy) return;
            busy=true;
            refreshButton.prop('disabled',true);
            $.ajax({url:'/api/v2/infrastructure/web-hard-ping',method:'GET',dataType:'json',cache:false,timeout:15000})
                .done(function(data){
                    if(!data || data.schema!=='ezs.web_hard_ping.v1' || !data.sites) throw new Error('HARD_PAYLOAD_INVALID');
                    names.forEach(name=>state(name,data.sites[name]));
                    const prod=data.sites.prod;
                    $('#http-prod').text(prod && prod.http && prod.http.status!==null ? String(prod.http.status) : 'Sans réponse');
                    $('#tcp-prod').text(prod && prod.ports && prod.ports['8501'] ? 'Ouvert' : 'Fermé');
                    feedback.text('Ping hard TCP/HTTP · '+(data.captured_utc||'mesuré'));
                }).fail(function(){
                    names.forEach(name=>state(name,null));
                    $('#http-prod').text('Sans réponse');
                    $('#tcp-prod').text('Inconnu');
                    feedback.text('Ping hard indisponible');
                }).always(function(){busy=false;refreshButton.prop('disabled',false);});
        }
        refreshButton.on('click',refresh);
        refresh();
        window.setInterval(function(){if(!document.hidden)refresh();},15000);
    });
})(window.jQuery);
