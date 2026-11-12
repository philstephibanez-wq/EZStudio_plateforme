/* EZS DEV diagnostic: read-only, source-preserving JSON, redacted before display. */
'use strict';
(() => {
    const panel = document.getElementById('ezs-debug-panel');
    if (!panel) return;
    const output = document.getElementById('ezs-debug-output');
    const status = document.getElementById('ezs-debug-status');
    const copy = document.getElementById('ezs-debug-copy');
    const refresh = document.getElementById('ezs-debug-refresh');
    const spinner = document.getElementById('ezs-debug-spinner');
    if (!output || !status || !copy || !refresh || !spinner) return;

    // Passive observation of native lifecycle commands issued by Infrastructure.
    // Never dispatch a command, observe only the existing jQuery transport.
    const ezsActionTrace = [];
    const ezsActionStarted = new WeakMap();
    const ezsActionPath = /^\/api\/v2\/infrastructure\/dev-groups\/(dev|lab|assistant)\/(start|restart|stop)$/;
    function ezsActionUrl(settings) {
        try {
            const url = new URL(settings.url, location.href);
            return url.origin === location.origin && ezsActionPath.test(url.pathname) ? url.pathname : null;
        } catch (_) { return null; }
    }
    function ezsActionParams(data) {
        // Explicit allowlist. Never retain the CSRF header or arbitrary request data.
        if (typeof data !== 'string') return {};
        const match = new URLSearchParams(data).get('confirmation');
        return match === null ? {} : {confirmation:match};
    }
    // jQuery events fire for the existing POST; this observer is read-only.
    if (window.jQuery) {
        window.jQuery(document).ajaxSend(function (_event, xhr, settings) {
            const path = ezsActionUrl(settings);
            if (!path || String(settings.type || settings.method).toUpperCase() !== 'POST') return;
            ezsActionStarted.set(xhr, {
                source:'infrastructure_jquery_ajax',
                timestamp_utc:new Date().toISOString(),
                began:performance.now(),
                request:{method:'POST',path:path,params:ezsActionParams(settings.data)}
            });
        });
        window.jQuery(document).ajaxComplete(function (_event, xhr, settings) {
            const begin = ezsActionStarted.get(xhr);
            if (!begin) return;
            ezsActionStarted.delete(xhr);
            const response = {http_status:xhr.status,body:null};
            // Source JSON is retained with field types; only secrets are redacted.
            if (xhr.responseJSON !== undefined) response.body = redact(xhr.responseJSON);
            else response.collection_note = 'NO_JSON_RESPONSE_BODY';
            const observation = {
                source:begin.source,
                timestamp_utc:begin.timestamp_utc,
                completed_utc:new Date().toISOString(),
                request:begin.request,
                response:response,
                duration_ms:Math.round(performance.now()-begin.began)
            };
            ezsActionTrace.push(observation);
            if (ezsActionTrace.length > 30) ezsActionTrace.shift();
            // Refresh the existing JSON console without launching another GET.
            // This snapshot contains only observations already collected.
            if (panel.open && output.textContent.startsWith('{')) {
                try {
                    const current = JSON.parse(output.textContent);
                    current.action_requests_and_responses = ezsActionTrace.slice();
                    output.textContent = JSON.stringify(current,null,2);
                } catch (_) { /* ongoing collection: next snapshot contains the trace */ }
            }
        });
    }

    const sources = [
        '/api/v2/infrastructure/web-hard-ping',
        '/api/v2/queue/dev-diagnostic'
    ];
    const secretKey = /(?:csrf|token|cookie|authorization|password|secret|api[_-]?key|private[_-]?key|session|credential)/i;
    const privateKey = /(?:email|user[_-]?id|client[_-]?ip|address|hostname|command[_-]?line|executable[_-]?path|file[_-]?path)/i;
    const MAX_BODY = 120000;
    let busy = false;
    const errors = [];
    window.addEventListener('error', event => {
        if (errors.length < 20) errors.push({kind:'error', message:String(event.message || 'SCRIPT_ERROR')});
    });
    window.addEventListener('unhandledrejection', () => {
        if (errors.length < 20) errors.push({kind:'unhandledrejection'});
    });

    function redact(value, depth = 0) {
        if (depth > 12) return '[TRONQUÉ : profondeur maximale]';
        if (Array.isArray(value)) return value.map(item => redact(item, depth + 1));
        if (value && typeof value === 'object') {
            const result = {};
            for (const [key, item] of Object.entries(value)) {
                result[key] = secretKey.test(key) || privateKey.test(key) ? '[MASQUÉ]' : redact(item, depth + 1);
            }
            return result;
        }
        if (typeof value === 'string' && value.length > MAX_BODY) return value.slice(0, MAX_BODY) + '[TRONQUÉ : longueur maximale]';
        return value;
    }

    function domDump() {
        const result = {};
        for (const group of ['dev','lab','assistant']) {
            const card = document.getElementById(group + '-title')?.closest('.ezs-server-card');
            const buttons = card ? [...card.querySelectorAll('.ezs-server-actions button')] : [];
            result[group] = {
                card_present: Boolean(card),
                data_ezs_state: card?.getAttribute('data-ezs-state') ?? null,
                data_ezs_probe: card?.getAttribute('data-ezs-probe') ?? null,
                buttons: buttons.map(b => ({text:b.textContent.trim(),disabled:b.disabled,aria_label:b.getAttribute('aria-label')}))
            };
        }
        return result;
    }

    async function collectSource(url) {
        const startedAt = performance.now();
        const timestamp = new Date().toISOString();
        const request = {method:'GET',path:url};
        try {
            const response = await fetch(url, {
                method:'GET',credentials:'same-origin',cache:'no-store',
                signal:AbortSignal.timeout(12000)
            });
            const raw = await response.text();
            if (raw.length > MAX_BODY) {
                return {source:url,timestamp_utc:timestamp,request,
                    response:{http_status:response.status,body:null,collection_error:'BODY_TOO_LARGE',body_characters:raw.length}};
            }
            let body;
            try { body = JSON.parse(raw); }
            catch (_) { body = {non_json_response:true,body_characters:raw.length}; }
            return {source:url,timestamp_utc:timestamp,request,
                response:{http_status:response.status,body:redact(body)},
                duration_ms:Math.round(performance.now()-startedAt)};
        } catch (error) {
            return {source:url,timestamp_utc:timestamp,request,
                response:{http_status:null,collection_error:String(error?.name || 'FETCH_ERROR')},
                duration_ms:Math.round(performance.now()-startedAt)};
        }
    }

    async function collect() {
        if (busy || !panel.open) return;
        busy = true;
        refresh.disabled = true;
        spinner.classList.remove('d-none');
        status.textContent = 'Collecte en cours…';
        const captures = [];
        // No interpretation of source values. Each GET result remains independent.
        for (const url of sources) captures.push(await collectSource(url));
        const snapshot = {
            captured_utc:new Date().toISOString(),
            browser:{path:location.pathname,document_ready_state:document.readyState,
                jquery_present:Boolean(window.jQuery),javascript_errors:redact(errors)},
            dom:{source:'DOM',timestamp_utc:new Date().toISOString(),data:domDump()},
            action_requests_and_responses:ezsActionTrace.slice(),
            requests_and_responses:captures
        };
        output.textContent = JSON.stringify(snapshot,null,2);
        status.textContent = 'JSON actualisé — ' + new Date().toLocaleTimeString('fr-FR');
        spinner.classList.add('d-none');
        refresh.disabled = false;
        busy = false;
    }
    refresh.addEventListener('click', () => { void collect(); });
    copy.addEventListener('click', async () => {
        const value = output.textContent;
        if (!value || !value.startsWith('{')) return;
        try {
            await navigator.clipboard.writeText(value);
            status.textContent = 'JSON affiché copié';
        } catch (_) {
            output.focus();
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(output);
            selection.removeAllRanges();
            selection.addRange(range);
            status.textContent = 'Sélection prête : Ctrl+C';
        }
    });
    panel.addEventListener('toggle', () => { if (panel.open) void collect(); });
    if (panel.open) void collect();
})();
