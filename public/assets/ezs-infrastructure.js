'use strict';
(function ($) {
    if (!$) return;
    $(function () {
        // Remove the redundant footer from the Infrastructure view.
        $('.ezs-footerline').remove();
        const $feedback = $('#dev-health-feedback');
        const $refresh = $('#refresh-dev-health');
        let pending = false;
        // Snapshot fusion: an HTTP response alone never proves the native process is running.
        const serviceTransport = {dev:null,lab:null,assistant:null};
        const serviceNative = {dev:null,lab:null,assistant:null};
        const groups = {dev:'#dev-title',lab:'#lab-title',assistant:'#assistant-title'};
        const serviceDesired = {dev:null,lab:null,assistant:null};
        function renderServiceBadge(group) {
            const native = serviceNative[group];
            const desired = serviceDesired[group];
            const transport = serviceTransport[group];
            const http = transport ? transport.http : null;
            const healthy = transport && transport.applicationHealthy !== false;
            const online = native === 'RUNNING' && desired === 'RUNNING' &&
                http !== null && http >= 200 && http < 400 && healthy;
            const offline = native === 'STOPPED' && desired === 'STOPPED';
            const label = online ? 'ONLINE' : offline ? 'OFFLINE' : 'ERREUR';
            const level = online ? 'success' : offline ? 'secondary' : 'danger';
            const $badge = $('#state-' + group);
            $badge.text(label).removeClass('text-bg-secondary text-bg-success text-bg-warning text-bg-danger')
                .addClass('text-bg-' + level);
            $badge.attr('title', 'Natif : ' + (native || 'NON_ATTESTE') +
                ' ; intention : ' + (desired || 'INCONNUE') +
                ' ; HTTP : ' + (http === null ? 'sans mesure' : http));
            const $open = $(groups[group] || '#missing-service').closest('.ezs-server-card')
                .find('.ezs-server-actions a').first();
            // Preserve href: never mutate the target; prevent activation when unavailable.
            $open.toggleClass('disabled', !online)
                .attr('aria-disabled', online ? 'false' : 'true')
                .attr('tabindex', online ? '0' : '-1');
        }
        function refresh() {
            if (pending) return;
            pending = true;
            $refresh.prop('disabled', true);
            $.ajax({url:'/api/v2/infrastructure/dev-health', method:'GET', dataType:'json', cache:false, timeout:9000})
              .done(function (data) {
                if (!data || data.schema !== 'ezs.dev_health.v1' || !Array.isArray(data.items)) throw new Error('bad_payload');
                const mapping = {ezscore:'dev',lab:'lab',assistant:'assistant'};
                let labBackend = null;
                data.items.forEach(function (item) {
                    if (item.component === 'lab' && item.kind === 'backend') { labBackend = item; return; }
                    const key = mapping[item.component];
                    if (!key) return;
                    $('#tcp-'+key).text(item.tcp_listening ? 'Ouvert' : 'Fermé');
                    $('#http-'+key).text(item.http_status === null ? 'Sans réponse' : String(item.http_status));
                    serviceTransport[key] = {
                        http:item.http_status, tcp:item.tcp_listening === true,
                        applicationHealthy:item.application_healthy
                    };
                    renderServiceBadge(key);
                });
                if (labBackend) {
                    $('#lab-backend').text(labBackend.tcp_listening ? 'FastCGI TCP ouvert' : 'Arrêt / absent');
                    if (labBackend.tcp_listening && $('#state-lab').text() === 'Façade absente') $('#state-lab').text('Backend actif · façade absente').removeClass('text-bg-secondary').addClass('text-bg-warning');
                }
                $feedback.text('Mesure DEV : ' + (data.timestamp_utc || 'récente'));
              })
              .fail(function () {
                  Object.keys(serviceTransport).forEach(function(group) {
                      serviceTransport[group] = null;
                      renderServiceBadge(group);
                  });
                  $feedback.text('Supervision DEV indisponible');
              })
              .always(function () { pending = false; $refresh.prop('disabled', false); });
        }
        function refreshBackend() {
            $.ajax({url:'/api/v2/infrastructure/dev-backend',method:'GET',dataType:'json',timeout:5000,cache:false})
              .done(function(data) {
                  if (!data || data.schema !== 'ezs.dev_backend.v1' || !data.item) return;
                  const d = data.item;
                  $('#dev-backend').text(d.tcp_listening ? 'Port TCP ouvert' : 'Arrêt / absent');
                  $('#dev-backend-ownership').text(d.ownership === 'legacy_exact' ? 'PID '+d.pid+' — serveur legacy identifié' : d.ownership === 'foreign' ? 'PID '+d.pid+' — processus étranger' : d.ownership === 'absent' ? 'Aucun listener' : 'Indéterminée');
                  if (d.tcp_listening && $('#state-dev').text() === 'Façade absente') {
                      $('#state-dev').text('Backend présent · façade absente').removeClass('text-bg-secondary').addClass('text-bg-warning');
                  }
              });
        }

        // Group control uses the existing buttons, without altering PROD or the Orchestrator root.
        const controls = {};
        let csrf = '';
        // No global action flag: one inflight operation per group.
        const modes = {dev:null,lab:null,assistant:null};
        // La vérification native peut prendre plusieurs secondes ; ne jamais simuler un état prêt.
        const groupRequestStart = {dev:0, lab:0, assistant:0};
        const $statusline = $('.ezs-statusline .text-body-secondary').first();
        $statusline.text('État des environnements — commandes DEV / LAB / Assistant vérifiées par le serveur');
        const actionDescriptions = {
            dev:'Commandes natives disponibles après vérification des processus',
            lab:'Démarrer / Redémarrer / Arrêter sous contrôle de l’Orchestrator',
            assistant:'Commandes natives supervisées ; aucune action sur les jobs ou l’IA'
        };
        Object.keys(groups).forEach(function (group) {
            const $card = $(groups[group]).closest('.ezs-server-card');
            $card.find('dt').filter(function () { return $(this).text().trim() === 'Action'; })
                .next('dd').text(actionDescriptions[group]);
            if (group === 'dev') $card.find('dt').filter(function () { return $(this).text().trim() === 'État complémentaire'; })
                .next('dd').text('État confirmé par l’inventaire natif avant toute commande');
            if (group === 'lab' || group === 'assistant') $card.find('dt').filter(function () { return $(this).text().trim() === 'Autorité Orchestrator'; })
                .next('dd').text('Orchestrator local · processus attestés · protection CSRF');
        });
        // EZS_GROUPS_STABLE_R82: seule une attestation change l'etat visible.
        let groupsPending = false;
        let groupsEpoch = 0;
        const rendered = {dev:null,lab:null,assistant:null};
        Object.keys(groups).forEach(function(group) {
            const $buttons = $(groups[group]).closest('.ezs-server-card').find('.ezs-server-actions button');
            controls[group] = {start:$buttons.eq(0), stop:$buttons.eq(1)};
            $buttons.removeAttr('title').prop('disabled', true);
            controls[group].start.text('État inconnu');
        });
        function disableAll() {
            Object.keys(controls).forEach(function(group) {
                controls[group].start.prop('disabled', true);
                controls[group].stop.prop('disabled', true);
            });
        }
        function indicateUnavailable(reason) {
            Object.keys(controls).forEach(function(group) {
                const card = $(groups[group]).closest('.ezs-server-card');
                card.attr('data-ezs-probe', 'unavailable');
                controls[group].start.attr('title', 'Permissions indisponibles : ' + reason);
                controls[group].stop.attr('title', 'Permissions indisponibles : ' + reason);
            });
        }
        function $cardFor(group) { return $(groups[group]).closest('.ezs-server-card'); }
        // Each group owns its own request, state and failure boundary.
        const groupPending = {dev:false,lab:false,assistant:false};
        const groupEpoch = {dev:0,lab:0,assistant:0};
        const groupActing = {dev:false,lab:false,assistant:false};
        function failGroup(group, reason) {
            controls[group].start.prop('disabled', true);
            controls[group].stop.prop('disabled', true);
            $cardFor(group).attr('data-ezs-probe','unavailable');
            serviceNative[group] = null;
            serviceDesired[group] = null;
            renderServiceBadge(group);
            controls[group].start.attr('title', 'Permissions indisponibles : ' + reason);
            controls[group].stop.attr('title', 'Permissions indisponibles : ' + reason);
        }
        function refreshGroup(group) {
            if (groupPending[group] || groupActing[group]) return;
            groupPending[group] = true;
            const epoch = ++groupEpoch[group];
            const startedAt = window.performance.now();
            $.ajax({
                url:'/api/v2/infrastructure/dev-group/'+group,
                method:'GET', dataType:'json', timeout:15000, cache:false
            }).done(function(data) {
                if (epoch !== groupEpoch[group] || groupActing[group]) return;
                if (!data || data.schema !== 'ezs.dev_group_single.v1' ||
                    data.group !== group || !data.permission || !data.csrf) {
                    failGroup(group,'réponse invalide');
                    return;
                }
                csrf = data.csrf;
                const state = data.permission;
                serviceNative[group] = ['RUNNING','STOPPED','PARTIAL'].includes(state.state) ? state.state : null;
                serviceDesired[group] = ['RUNNING','STOPPED'].includes(state.desired) ? state.desired : null;
                renderServiceBadge(group);
                const mode = state.state === 'RUNNING' && state.restart === true ? 'restart'
                    : state.state === 'STOPPED' && state.start === true ? 'start' : null;
                const next = {state:state.state || 'UNVERIFIED',mode:mode,stop:state.stop === true};
                const $start = controls[group].start;
                const $stop = controls[group].stop;
                modes[group] = mode;
                $start.text(mode === 'restart' ? 'Redémarrer' : mode === 'start' ? 'Démarrer' : 'Indisponible');
                $start.attr('aria-label', mode === 'restart' ? 'Redémarrer '+group : mode === 'start' ? 'Démarrer '+group : 'Commande indisponible pour '+group);
                $stop.attr('aria-label','Arrêter '+group);
                $cardFor(group).attr('data-ezs-state',next.state);
                $start.toggleClass('btn-outline-primary',mode !== null)
                      .toggleClass('btn-outline-secondary',mode === null);
                $stop.toggleClass('btn-outline-danger',next.stop)
                     .toggleClass('btn-outline-secondary',!next.stop);
                $start.prop('disabled',mode === null);
                $stop.prop('disabled',next.stop !== true);
                $cardFor(group).attr('data-ezs-stop-authorized',next.stop ? 'true':'false')
                    .attr('data-ezs-probe','verified')
                    .attr('data-ezs-state-probe-ms',String(Math.round(window.performance.now()-startedAt)));
            }).fail(function(xhr,textStatus) {
                if (epoch !== groupEpoch[group] || groupActing[group]) return;
                failGroup(group,(xhr && xhr.status) || textStatus || 'réseau');
            }).always(function() {
                groupPending[group] = false;
            });
        }
        function refreshGroups() {
            Object.keys(controls).forEach(function(group) { refreshGroup(group); });
        }
        // EZS_DIALOG_CONFIRM_V1: no window.confirm/alert/prompt or OS message box.
        function confirmEzs(message) {
            return new Promise(function (resolve) {
                const dialog = document.createElement('dialog');
                dialog.setAttribute('aria-label', 'Confirmer l’action');
                dialog.className = 'ezs-confirm-dialog';
                dialog.style.cssText = 'border:1px solid #777;border-radius:12px;padding:0;max-width:min(460px,94vw);width:100%;background:var(--bs-body-bg,#fff);color:var(--bs-body-color,#222);box-shadow:0 12px 48px #0006';
                const body = document.createElement('div');
                body.className = 'p-4';
                const title = document.createElement('h2');
                title.className = 'h5';
                title.textContent = 'Confirmer l’action';
                const detail = document.createElement('p');
                detail.textContent = message;
                const actions = document.createElement('div');
                actions.className = 'd-flex gap-2 justify-content-end mt-3';
                const cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'btn btn-outline-secondary';
                cancel.textContent = 'Annuler';
                const accept = document.createElement('button');
                accept.type = 'button';
                accept.className = 'btn btn-primary';
                accept.textContent = 'Confirmer';
                actions.append(cancel, accept);
                body.append(title, detail, actions);
                dialog.appendChild(body);
                document.body.appendChild(dialog);
                let settled = false;
                function settle(accepted) {
                    if (settled) return;
                    settled = true;
                    dialog.close();
                    dialog.remove();
                    resolve(accepted);
                }
                cancel.addEventListener('click', function () { settle(false); });
                accept.addEventListener('click', function () { settle(true); });
                dialog.addEventListener('cancel', function (event) { event.preventDefault(); settle(false); });
                try { dialog.showModal(); cancel.focus(); }
                catch (_) { settle(false); }
            });
        }
        async function action(group, verb) {
            if (!controls[group]) return;
            const button = verb === 'stop' ? controls[group].stop : controls[group].start;
            if (groupActing[group] || !csrf || !button || button.prop('disabled')) return;
            const label = {dev:'EZScore DEV',lab:'EZStudio LAB',assistant:'Assistant'}[group];
            const confirmation = verb === 'stop' ?
                'Arrêter les processus natifs de '+label+' ? Les travaux en cours peuvent être interrompus. Les autres groupes et l’IA restent inchangés.' :
                verb === 'restart' ? 'Redémarrer les processus natifs de '+label+' ? Les travaux en cours peuvent être interrompus. Si la relance échoue, le groupe peut rester arrêté. PROD et les autres groupes restent inchangés.' :
                'Démarrer uniquement les processus natifs de '+label+' ?';
            if (!(await confirmEzs(confirmation))) return;
            groupActing[group] = true;
            controls[group].start.prop('disabled', true);
            controls[group].stop.prop('disabled', true);
            $feedback.text((verb === 'start' ? 'Démarrage' : verb === 'restart' ? 'Redémarrage' : 'Arrêt')+' '+label+' en cours…');
            $.ajax({
                url:'/api/v2/infrastructure/dev-groups/'+group+'/'+verb,
                method:'POST',
                headers:{'X-EZS-CSRF':csrf},
                data:{confirmation:verb.toUpperCase()+'_'+group.toUpperCase()},
                dataType:'json',
                timeout:125000
            }).done(function() {
                $feedback.text('Commande '+label+' exécutée ; actualisation de l’état.');
            }).fail(function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'commande indisponible';
                $feedback.text('Commande '+label+' refusée : '+message);
            }).always(function() {
                groupActing[group] = false;
                refreshGroup(group);
            });
        }
        $('.ezs-server-card .ezs-server-actions a').on('click', function(event) {
            if ($(this).attr('aria-disabled') === 'true') event.preventDefault();
        });
        Object.keys(controls).forEach(function(group) {
            controls[group].start.on('click', function(){ if (modes[group]) action(group,modes[group]); });
            controls[group].stop.on('click', function(){ action(group,'stop'); });
        });
        $refresh.on('click', function(){ refresh(); refreshBackend(); refreshGroups(); });
        refresh(); refreshBackend(); refreshGroups();
        window.setInterval(function () { if (!document.hidden) {refresh(); refreshBackend(); refreshGroups();} }, 15000);
    });
})(window.jQuery);
