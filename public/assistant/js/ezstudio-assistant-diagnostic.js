/* Backoffice technical diagnostic: read-only collections of existing APIs. */
(function () {
    'use strict';
    const root = document.getElementById('ezs-assistant-diagnostic');
    if (!root) return;
    const output = document.getElementById('ezs-assistant-diagnostic-json');
    const status = document.getElementById('ezs-assistant-diagnostic-status');
    const refresh = document.getElementById('ezs-assistant-diagnostic-refresh');
    const copy = document.getElementById('ezs-assistant-diagnostic-copy');
    let busy = false;
    const sensitive = /(token|secret|password|passwd|cookie|authorization|api[_-]?key|credential|session)/i;
    const privatePath = /(?:[a-z]:\\|\\\\|\/home\/|\/users\/)/i;

    function redact(value, key = '', depth = 0) {
        if (sensitive.test(key)) return '[MASQUÉ]';
        if (depth > 12) return '[PROFONDEUR LIMITÉE]';
        if (Array.isArray(value)) return value.map(v => redact(v, '', depth + 1));
        if (value && typeof value === 'object') {
            const clean = {};
            Object.keys(value).forEach(k => {clean[k] = redact(value[k], k, depth + 1);});
            return clean;
        }
        if (typeof value === 'string' && privatePath.test(value)) return '[CHEMIN PRIVÉ MASQUÉ]';
        return value;
    }
    async function source(name, url) {
        const collectedAt = new Date().toISOString();
        let httpStatus = null;
        try {
            const response = await fetch(url, {method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            httpStatus = response.status;
            const raw = await response.text();
            if (raw.length > 65536) throw new Error('response_too_large');
            let data;
            try {data = JSON.parse(raw);} catch (_) {throw new Error('invalid_json');}
            return {source: name, collected_at_utc: collectedAt, http_status: response.status, collection_error: null, data: redact(data)};
        } catch (error) {
            const code = error.message === 'invalid_json' ? 'invalid_json' : error.message === 'response_too_large' ? 'response_too_large' : 'transport_error';
            return {source: name, collected_at_utc: collectedAt, http_status: httpStatus, collection_error: code, data: null};
        }
    }
    async function collect() {
        if (busy) return;
        busy = true;
        status.textContent = 'Collecte…';
        try {
            const results = await Promise.all([
                source('assistant.api.status', '/api/status'),
                source('assistant.api.backoffice.inference', '/api/backoffice/inference')
            ]);
            output.textContent = JSON.stringify({schema: 'ezs.assistant-diagnostic.v1', collections: results}, null, 2);
            status.textContent = results.every(r => r.collection_error === null && r.http_status === 200) ? 'Collecte terminée' : 'Collecte partielle ou indisponible';
        } finally {busy = false;}
    }
    refresh.addEventListener('click', collect);
    root.addEventListener('toggle', () => {if (root.open) collect();});
    copy.addEventListener('click', async () => {
        try {await navigator.clipboard.writeText(output.textContent);status.textContent = 'JSON copié';}
        catch (_) {status.textContent = 'Copie indisponible';}
    });
}());
