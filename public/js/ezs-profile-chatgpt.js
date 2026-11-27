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
    function renderReport(data) {

        result.querySelector('[data-report-label]').textContent =
            'Analyse #' + data.run + ' · Prompt #' + data.prompt_revision;
        if (typeof data.jsonl === 'string') {
            jsonl.value = data.jsonl;
            copyJsonl.disabled = false;
            copyStatus.textContent = '';
            result.querySelector('[data-report-sources]').replaceChildren();
            for (const row of table?.querySelectorAll('[data-expertise-item]') ?? []) {
                row.querySelector('[data-verdict]').textContent = '—';
                row.querySelector('[data-sources]').textContent = '—';
            }
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
    queueRequest({read: '1'}).then(data => {
        if (valid(data) && !button.disabled && !reports.some(report => report.jsonl === data.jsonl)) {
            reports.push(data);
            renderReport(data);
            choices();
        }
    }).catch(() => {});
})();
