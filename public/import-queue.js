(() => {
    const progress = document.querySelector('[data-import-progress]');
    if (!progress) return;

    const rows = [...progress.querySelectorAll('[data-import-progress-row]')];
    const updateVisibility = () => { progress.hidden = rows.every(row => row.hidden); };
    const updateProgress = Object.fromEntries(rows.map(row => {
        const bar = row.querySelector('progress');
        const label = row.querySelector('[data-import-progress-label]');
        const dismiss = row.querySelector('button');
        let dismissedTotal = null;

        dismiss.addEventListener('click', () => {
            dismissedTotal = bar.max;
            row.hidden = true;
            updateVisibility();
            const nextLink = progress.querySelector('[data-import-progress-row]:not([hidden]) a');
            (nextLink ?? document.querySelector('.site-menu > summary'))?.focus();
        });

        return [row.dataset.importProgressRow, (finished, total, text) => {
            const complete = total > 0 && finished === total;
            if (!complete || dismissedTotal !== total) dismissedTotal = null;
            row.hidden = total === 0 || dismissedTotal !== null;
            dismiss.hidden = !complete;
            bar.max = Math.max(1, total);
            bar.value = finished;
            label.textContent = text;
        }];
    }));
    const indexingStages = document.querySelectorAll('[data-indexing-stage]');
    const details = document.querySelector('[data-import-queue-files]');
    const count = document.querySelector('[data-import-queue-count]');
    const endpoint = new URL(progress.dataset.importProgress, document.baseURI);
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
            updateProgress.files(status.finished, status.total, text);
            updateProgress.indexing(prepared, passageTotal, `${prepared} of ${passageTotal} passages processed`);
            updateVisibility();

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
