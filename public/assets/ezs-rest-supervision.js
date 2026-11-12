'use strict';
(function ($) {
    if (!$) return;
    $(function () {
        if (!document.getElementById('ezs-rest-supervision-state')) return;
        const $state = $('#ezs-rest-supervision-state');
        const endpoint = '/api/v2/infrastructure/rest-supervision';
        let monitoring = false;
        let attempts = 0;
        function show(data) {
            if (!data) return;
            const label = data.state === 'RUNNING' ? 'Réconciliation des services en cours'
                : data.state === 'FAILED' ? 'Échec de réconciliation — consulter les journaux'
                : 'Réconciliation des services terminée';
            $state.text(label + (data.last_group ? ' · ' + data.last_group : ''));
        }
        function poll() {
            if (!monitoring || ++attempts > 90) {
                monitoring = false;
                if (attempts > 90) $state.text('Réconciliation longue — consulter le diagnostic');
                return;
            }
            $.ajax({url:endpoint,method:'GET',dataType:'json',cache:false,timeout:4000})
                .done(function (data) {
                    show(data);
                    if (data.state !== 'RUNNING') monitoring = false;
                })
                .always(function () { if (monitoring) window.setTimeout(poll, 10000); });
        }
        $.ajax({url:endpoint,method:'POST',dataType:'json',timeout:5000})
            .done(function (data) {
                show(data);
                if (data.state === 'RUNNING') { monitoring = true; poll(); }
            })
            .fail(function (xhr) {
                $state.text('Démarrage automatique indisponible (' + xhr.status + ') — aucune action forcée');
            });
    });
})(window.jQuery);
