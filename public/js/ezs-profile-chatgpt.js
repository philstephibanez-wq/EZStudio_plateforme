(() => {
    const button = document.querySelector('[data-profile-chatgpt]');
    if (!button) return;
    const status = document.getElementById('profile-chatgpt-status');
    const result = document.getElementById('profile-chatgpt-result');
    const jsonl = result.querySelector('[data-report-text]');
    const copyJsonl = result.querySelector('[data-copy-jsonl]');
    const copyStatus = result.querySelector('[data-copy-status]');
    copyJsonl.addEventListener('click', async () => {
        if (!jsonl.value) return;
        try {
            await navigator.clipboard.writeText(jsonl.value);
            copyStatus.textContent = 'JSONL copié';
        } catch {
            jsonl.focus();
            jsonl.select();
            if (document.execCommand('copy')) copyStatus.textContent = 'JSONL copié';
            else copyStatus.textContent = 'Texte sélectionné. Utilise Ctrl+C.';
        }
    });
    const panel = document.getElementById('profile-chatgpt-progress');
    const bar = panel.querySelector('progress');
    const table = document.querySelector('#profile-expertise-table table');
    const canonical = document.getElementById('profile-canonical-results');
    const spacer = document.querySelector('[data-expertise-spacer]');
    function alignTables() {
        const left = canonical?.querySelector('table');
        if (!left || !table) return;
        const rows = Array.from(left.rows);
        const peers = Array.from(table.rows);
        for (const row of [...rows, ...peers]) row.style.height = '';
        spacer.style.height = '0px';
        left.style.marginTop = '';
        if (!matchMedia('(min-width:1101px)').matches) return;
        const offset = left.getBoundingClientRect().top - table.getBoundingClientRect().top;
        if (offset >= 0) spacer.style.height = offset + 'px';
        else left.style.marginTop = (parseFloat(getComputedStyle(left).marginTop) - offset) + 'px';
        rows.forEach((row, i) => {
            const peer = peers[i];
            if (!peer) return;
            const height = Math.ceil(Math.max(row.getBoundingClientRect().height, peer.getBoundingClientRect().height));
            row.style.height = peer.style.height = height + 'px';
        });
    }
    addEventListener('resize', alignTables);
    requestAnimationFrame(alignTables);
    const steps = Array.from(panel.querySelectorAll('li'));
    const order = ['export', 'oauth', 'search'];
    const draw = (index, failed = false) => {
        bar.value = Math.max(0, index);
        steps.forEach((step, position) => {
            if (position === index) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
            step.dataset.state = position < index ? 'done' : position === index && failed ? 'failed' : '';
        });
    };
    const revision = document.getElementById('profile-chatgpt-revision');
    const key = 'ezs-expertise:' + button.dataset.song + ':' + button.dataset.run;
    const https = url => typeof url === 'string' && url.startsWith('https://') && URL.canParse(url);
    const valid = data => data && String(data.run) === button.dataset.run
        && (typeof data.jsonl === 'string' && data.jsonl.trim().length > 0 ||
        Array.isArray(data.items) && data.items.length > 0 && data.items.every(item => item &&
            typeof item.item_id === 'string' && (typeof item.verdict === 'string' || item.verdict === null) &&
            typeof item.justification === 'string' && Array.isArray(item.sources) && item.sources.every(https))
        && Array.isArray(data.sources) && data.sources.every(https));
    function readReports() {
        const saved = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(saved) ? saved.filter(valid) : [];
    }
    async function queueRequest(fields) {
        const response = await fetch(button.dataset.profileChatgpt, {
            method: 'POST',
            headers: {'X-EZS-Expertise': 'chatgpt'},
            body: new URLSearchParams({song: button.dataset.song, run: button.dataset.run, ...fields}),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'FIFO indisponible.');
        return data;
    }
    let reports = [];
    try { reports = readReports(); } catch {}
    function showReferences(data) {
        if (String(data.run) !== button.dataset.run || !Array.isArray(data.references)) return;
        const items = new Map(data.references.map(item => [item.item_id, item]));
        for (const row of table?.querySelectorAll('[data-expertise-item]') ?? []) {
            const item = items.get(row.dataset.expertiseItem);
            if (!item || item.valeur == null) continue;
            const value = Array.isArray(item.valeur) && item.valeur.length === 0 ? 'Aucun élément confirmé' :
                typeof item.valeur === 'string' ? item.valeur : JSON.stringify(item.valeur);
            row.querySelector('[data-verdict]').textContent = 'Référence : ' + value;
            const cell = row.querySelector('[data-sources]');
            cell.replaceChildren();
            for (const url of item.sources.filter(https)) {
                const link = document.createElement('a');
                link.href = url;
                link.textContent = new URL(url).hostname;
                link.rel = 'noopener noreferrer';
                link.target = '_blank';
                cell.append(link, document.createElement('br'));
            }
        }
        alignTables();
    }
    // EZS_PROFILE_REFERENCE_R1: read-only projection of the stored documentary report.
    function decodeDocumentaryJsonl(raw) {
        const allowed = new Set(['1','1a','1b','1c','2','3','4','5','6','7','8','9']);
        const items = new Map(), duplicates = new Set();
        let errors = 0;
        let lines = raw.replace(/^\uFEFF/, '').trim().split(/\r?\n/);
        if (/^```(?:jsonl|json|ndjson)?\s*$/i.test(lines[0]) && /^```\s*$/.test(lines.at(-1))) {
            lines = lines.slice(1,-1);
        }
        for (const line of lines) {
            if (!line.trim()) continue;
            let item;
            try { item = JSON.parse(line); } catch (_) { errors++; continue; }
            if (!item || typeof item !== 'object' || Array.isArray(item) || !allowed.has(item.item_id)
                || !Object.hasOwn(item,'valeur') || !Array.isArray(item.sources)) { errors++; continue; }
            if (items.has(item.item_id) || duplicates.has(item.item_id)) {
                duplicates.add(item.item_id); items.delete(item.item_id); errors++; continue;
            }
            items.set(item.item_id,item);
        }
        return {items,duplicates,errors};
    }
    function referenceValue(id, value) {
        if (value === null) return 'Non documenté';
        if (typeof value === 'string') return value;
        if (id === '2' && typeof value === 'number' && Number.isFinite(value)) return String(value) + ' s';
        if (id === '4' && value && !Array.isArray(value) && typeof value === 'object'
            && typeof value.bpm === 'number' && typeof value.unite === 'string') {
            return String(value.bpm) + ' BPM · ' + value.unite;
        }
        if (Array.isArray(value)) return value.length ? value.map(v => referenceValue('',v)).join(' ; ') : 'Liste vide (référence)';
        return JSON.stringify(value);
    }
    function renderDocumentaryJsonl(raw) {
        const decoded = decodeDocumentaryJsonl(raw);
        const allSources = new Set();
        for (const row of table?.querySelectorAll('[data-expertise-item]') ?? []) {
            const id = row.dataset.expertiseItem;
            const item = decoded.items.get(id);
            const cell = row.querySelector('[data-verdict]');
            const sources = row.querySelector('[data-sources]');
            cell.style.whiteSpace = 'normal'; cell.style.overflowWrap = 'anywhere';
            sources.style.whiteSpace = 'normal'; sources.style.overflowWrap = 'anywhere';
            cell.textContent = decoded.duplicates.has(id) ? 'Références multiples : conflit' : item ? referenceValue(id,item.valeur) : 'Non fourni dans la fiche';
            if (item && typeof item.commentaire === 'string' && item.commentaire.trim()) {
                const comment = document.createElement('span');
                comment.textContent = item.commentaire;
                cell.append(document.createElement('br'),comment);
            }
            sources.replaceChildren();
            for (const url of new Set(item?.sources ?? [])) {
                if (!https(url)) continue;
                const link = document.createElement('a');
                link.href = url; link.textContent = new URL(url).hostname;
                link.title = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                sources.append(link,document.createElement('br')); allSources.add(url);
            }
            if (!sources.childNodes.length) sources.textContent = '—';
        }
        if (decoded.errors) result.querySelector('[data-report-label]').textContent += ' · ' + decoded.errors + ' ligne(s) invalide(s) ou en conflit';
        const list = result.querySelector('[data-report-sources]');
        list.replaceChildren();
        for (const url of allSources) {
            const li = document.createElement('li'); const a = document.createElement('a');
            a.href = url; a.textContent = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
            li.append(a); list.append(li);
        }
    }
    function renderReport(data) {

        result.querySelector('[data-report-label]').textContent =
            'Analyse #' + data.run + ' · Prompt #' + data.prompt_revision;
        if (typeof data.jsonl === 'string') {
            jsonl.value = data.jsonl;
            copyJsonl.disabled = false;
            copyStatus.textContent = '';
            result.querySelector('[data-report-sources]').replaceChildren();
            renderDocumentaryJsonl(data.jsonl);
            alignTables();
            draw(order.length);
            return;
        }
        jsonl.value = data.kind === 'fiche_reference'
            ? data.references.map(item => JSON.stringify(item)).join('\n') : '';
        const items = new Map(data.items.map(item => [item.item_id, item]));
        for (const row of table?.querySelectorAll('[data-expertise-item]') ?? []) {
            const item = items.get(row.dataset.expertiseItem);
            if (!item) continue;
            row.querySelector('[data-verdict]').textContent = [item.verdict, item.justification].filter(Boolean).join(' · ');
            const cell = row.querySelector('[data-sources]');
            cell.replaceChildren();
            for (const url of item.sources) {
                const link = document.createElement('a');
                link.href = url;
                link.textContent = new URL(url).hostname;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                cell.append(link, document.createElement('br'));
            }
            if (!item.sources.length) cell.textContent = '—';
        }
        if (data.kind === 'fiche_reference') {
            showReferences(data);
        }
        alignTables();
        const sources = result.querySelector('[data-report-sources]');
        sources.replaceChildren();
        for (const url of data.sources) {
            if (!url.startsWith('https://')) continue;
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = url;
            link.rel = 'noopener noreferrer';
            link.target = '_blank';
            link.textContent = url;
            li.append(link);
            sources.append(li);
        }
        copyJsonl.disabled = !jsonl.value;
        copyStatus.textContent = '';
        draw(order.length);
    }
    function choices() {
        if (!reports.length) return;
        revision.replaceChildren(...reports.map((_, i) => new Option('Expertise #' + (i + 1), i)));
        revision.value = String(reports.length - 1);
        revision.disabled = button.disabled;
    }
    revision.addEventListener('change', () => {
        const data = reports[Number(revision.value)];
        if (!data) return;
        renderReport(data);
        status.textContent = revision.selectedOptions[0].textContent + ' terminée';
    });
    choices();
    if (reports.length) {
        renderReport(reports.at(-1));
        status.textContent = 'Réponse disponible dans Fiche JSONL';
    }
    const labels = {
        export: 'Préparation du run',
        oauth: 'Connexion OpenAI à confirmer dans le navigateur',
        search: 'Connexion validée · Recherche de sources',
        compare: 'Sources reçues · Comparaison avec le run',
    };
    button.addEventListener('click', async () => {
        if (button.disabled) return;
        button.disabled = true;
        revision.disabled = true;
        panel.hidden = false;
        alignTables();
        let active = 0;
        draw(active);
        let stage = 'Démarrage';
        const started = Date.now();
        const showStage = () => {
            status.textContent = stage + ' · ' + Math.floor((Date.now() - started) / 1000) + ' s';
        };
        showStage();
        const timer = setInterval(showStage, 1000);
        try {
            let data = await queueRequest({});
            if (data.queue_id) {
                stage = 'En attente dans la FIFO';
                while (true) {
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    const state = await queueRequest({queue: data.queue_id, revision: data.revision});
                    if (state.result) {
                        data = state.result;
                        break;
                    }
                    if (!['waiting', 'progressing', 'cancelling'].includes(state.status)) {
                        throw new Error('Expertise interrompue. Vérifie le job dans l’Orchestrator.');
                    }
                    stage = state.status === 'progressing' ? 'Expertise ChatGPT' : 'En attente dans la FIFO';
                }
            }
            if (!valid(data)) throw new Error('Rapport invalide.');
            renderReport(data);
            try {
                const saved = readReports();
                if (saved.length >= reports.length) reports = saved;
            } catch {}
            reports.push(data);
            choices();
            try {
                localStorage.setItem(key, JSON.stringify(reports));
                status.textContent = 'Réponse disponible dans Fiche JSONL';
            } catch {
                status.textContent = 'Expertise terminée (historique dans cet onglet uniquement)';
            }
        } catch (error) {
            draw(active, true);
            status.textContent = 'Arrêt · ' + stage + ' · ' + (error.message || 'Expertise interrompue.');
        } finally {
            clearInterval(timer);
            button.disabled = false;
            revision.disabled = !reports.length;
        }
    });

    // Observe global FIFO attempts without submitting any new job.
    let monitored = false;
    let reading = false;
    async function readPersisted() {
        if (reading || button.disabled) return;
        reading = true;
        try {
            const data = await queueRequest({read:'1'});
            if (valid(data) && !reports.some(r => r.jsonl === data.jsonl)) {
                reports.push(data); renderReport(data); choices();
                status.textContent = 'Réponse disponible dans Fiche JSONL';
            }
        } catch (_) {} finally { reading = false; }
    }
    window.addEventListener('ezs-expertise-monitor', event => {
        if (button.disabled) return;
        const a = event.detail.find(x => String(x.song) === button.dataset.song && String(x.run) === button.dataset.run);
        if (a) {
            monitored = true; panel.hidden = false;
            const phase = a.stage === 'result' ? 2 : ['search'].includes(a.stage) ? 2 : ['oauth','registration','models','model_select','session_lock'].includes(a.stage) ? 1 : 0;
            draw(phase);
            status.textContent = (a.stage === 'result' ? 'Enregistrement' : labels[order[phase]]) + ' · ' + Math.max(0,Math.floor(Date.now()/1000-a.started_at)) + ' s';
        } else if (monitored) {
            monitored = false;
            status.textContent = 'Exécution terminée ou interrompue · Vérification du rapport';
            readPersisted();
        }
    });
    setInterval(() => { if (!document.hidden && !monitored) readPersisted(); }, 5000);
    queueRequest({read: '1'}).then(data => {
        if (valid(data) && !button.disabled && !reports.some(report => report.jsonl === data.jsonl)) {
            reports.push(data);
            renderReport(data);
            choices();
        }
    }).catch(() => {});
})();
