(() => {
    const button = document.querySelector('[data-profile-expertise-all]');
    if (!button) return;
    const status = document.getElementById('profile-expertise-all-status');
    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Admission dans la FIFO…';
        try {
            const response = await fetch(button.dataset.profileExpertiseAll, {
                method: 'POST',
                headers: {'X-EZS-Expertise': 'chatgpt'},
                body: new URLSearchParams({all: '1'}),
            });
            const data = await response.json();
            status.textContent = (data.error ? data.error + ' ' : '') +
                (data.queued ?? 0) + ' dans la file · ' + (data.done ?? 0) + ' déjà terminées';
        } catch {
            status.textContent = 'Admission interrompue. Relance pour reprendre.';
        } finally {
            button.disabled = false;
        }
    });
})();
