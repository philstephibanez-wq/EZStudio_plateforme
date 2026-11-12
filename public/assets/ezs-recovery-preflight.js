(function ($) {
    'use strict';
    if (!$) return;
    $(function () {
        var $status = $('#ezs-web-recovery-preflight-status');
        if (!$status.length) return;
        $.ajax({url:'/api/v2/infrastructure/recovery-preflight', type:'GET', dataType:'json', cache:false, timeout:6000})
            .done(function(data) {
                if (!data || data.schema !== 'ezs.web_recovery_preflight.v1') {
                    $status.text('Diagnostic de supervision non valide'); return;
                }
                $status.text(data.recovery_enabled ? 'Supervision disponible' :
                    'Supervision FastCGI non active : lancement Windows non qualifié');
                $status.attr('data-ezs-recovery-reason', data.reason || '');
            }).fail(function () { $status.text('Diagnostic de supervision indisponible'); });
    });
})(window.jQuery);
