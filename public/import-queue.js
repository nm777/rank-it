(() => {
    const link = document.querySelector('[data-import-progress]');
    if (!link) return;

    const bar = link.querySelector('[data-import-file-bar]');
    const label = link.querySelector('[data-import-progress-label]');
    const indexing = link.querySelector('[data-import-indexing]');
    const indexingBar = indexing.querySelector('progress');
    const indexingLabel = indexing.querySelector('[data-import-indexing-label]');
    const indexingStages = document.querySelectorAll('[data-indexing-stage]');
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
            const {screening, embedding, indexed, skipped} = status.indexing;
            const prepared = indexed + skipped;
            const passageTotal = prepared + screening + embedding;
            link.hidden = status.total === 0 && passageTotal === 0;
            bar.max = Math.max(1, status.total);
            bar.value = status.finished;
            label.textContent = text;

            indexing.hidden = passageTotal === 0;
            indexingBar.max = Math.max(1, passageTotal);
            indexingBar.value = prepared;
            indexingLabel.textContent = `${prepared} of ${passageTotal} passages processed`;

            if (details) {
                count.textContent = text;
                for (const stage of indexingStages) {
                    stage.textContent = status.indexing[stage.dataset.indexingStage];
                }
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
