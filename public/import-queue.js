(() => {
    const link = document.querySelector('[data-import-progress]');
    if (!link) return;

    const bar = link.querySelector('progress');
    const label = link.querySelector('[data-import-progress-label]');
    const details = document.querySelector('[data-import-queue-files]');
    const count = document.querySelector('[data-import-queue-count]');
    const endpoint = new URL(link.href);
    if (details) endpoint.searchParams.set('details', '1');

    let checking = false;
    let previousDetails;

    async function refresh() {
        if (document.hidden || checking) return;
        checking = true;

        try {
            const response = await fetch(endpoint, {cache: 'no-store', headers: {Accept: 'application/json'}});
            if (!response.ok) return;

            const status = await response.json();
            const text = `${status.finished} of ${status.total} files processed`;
            link.hidden = status.total === 0 || (link.hidden && status.finished === status.total);
            bar.max = Math.max(1, status.total);
            bar.value = status.finished;
            label.textContent = text;

            if (details) {
                count.textContent = text;
                if (previousDetails !== status.details && !details.contains(document.activeElement)) {
                    details.innerHTML = status.details;
                    previousDetails = status.details;
                }
            }
        } catch {
            // Keep the last known state until the next refresh.
        } finally {
            checking = false;
        }
    }

    document.addEventListener('visibilitychange', refresh);
    window.setInterval(refresh, 5000);
    refresh();
})();
