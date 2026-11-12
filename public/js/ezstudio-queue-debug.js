/* Local DEV queue diagnostic: faithful, redacted-by-selection JSON; no controls. */
(function () {
    'use strict';
    const root = document.getElementById('ezstudio-queue-diagnostic');
    if (!root) return;
    const output = document.getElementById('ezstudio-queue-diagnostic-output');
    const state = document.getElementById('ezstudio-queue-diagnostic-status');
    const refresh = document.getElementById('ezstudio-queue-diagnostic-refresh');
    const copy = document.getElementById('ezstudio-queue-diagnostic-copy');
    const url = root.dataset.url;
    let busy = false;

    async function collect() {
        if (busy) return;
        busy = true;
        state.textContent = 'Collecte en cours…';
        try {
            const response = await fetch(url, {method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            const body = await response.json();
            output.textContent = JSON.stringify(body, null, 2);
            state.textContent = response.ok ? 'Collecte terminée' : 'Erreur HTTP ' + response.status;
        } catch (error) {
            output.textContent = JSON.stringify({
                schema: 'ezstudio.local-queue-diagnostic.v1',
                collection: {source: 'lab.local-queue', collected_at_utc: new Date().toISOString(), collection_error: 'transport_error'},
                data: null
            }, null, 2);
            state.textContent = 'Collecte indisponible';
        } finally {
            busy = false;
        }
    }
    refresh.addEventListener('click', collect);
    root.addEventListener('toggle', () => {if (root.open) collect();});
    copy.addEventListener('click', async () => {
        try {await navigator.clipboard.writeText(output.textContent);state.textContent = 'JSON copié';}
        catch (_) {state.textContent = 'Copie indisponible';}
    });
}());
