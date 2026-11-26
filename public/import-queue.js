(() => {
    const progress = document.querySelector('[data-import-progress]');
    if (!progress) return;

    const endpoint = new URL(progress.dataset.importProgress, document.baseURI);
    const rows = [...progress.querySelectorAll('[data-import-progress-row]')];
    const updateVisibility = () => { progress.hidden = rows.every(row => row.hidden); };
    const updateProgress = Object.fromEntries(rows.map(row => {
        const bar = row.querySelector('progress');
        const label = row.querySelector('[data-import-progress-label]');
        const dismiss = row.querySelector('button');
        const kind = row.dataset.importProgressRow;
        const storageKey = `rank-it:${endpoint.pathname}:${progress.dataset.progressOwner}:${kind}:dismissed`;
        let dismissedRevision = null;
        let currentRevision = null;

        try {
            dismissedRevision = localStorage.getItem(storageKey);
        } catch {
            // Dismissal still works on this page if storage is unavailable.
        }

        function saveDismissal(revision) {
            dismissedRevision = revision;
            try {
                if (revision === null) localStorage.removeItem(storageKey);
                else localStorage.setItem(storageKey, revision);
            } catch {
                // Keep the in-page dismissal.
            }
        }

        dismiss.addEventListener('click', () => {
            saveDismissal(currentRevision);
            row.hidden = true;
            updateVisibility();
            const nextLink = progress.querySelector('[data-import-progress-row]:not([hidden]) a');
            (nextLink ?? document.querySelector('.site-menu > summary'))?.focus();
        });

        return [kind, (finished, total, text, revision, blocked = 0) => {
            const canDismiss = total > 0 && finished + blocked === total;
            currentRevision = revision;
            if (dismissedRevision !== null && (!canDismiss || dismissedRevision !== revision)) saveDismissal(null);
            row.hidden = total === 0 || dismissedRevision !== null;
            dismiss.hidden = !canDismiss;
            bar.max = Math.max(1, total);
            bar.value = finished;
            label.textContent = text;
        }];
    }));
    const indexingStages = document.querySelectorAll('[data-indexing-stage]');
    const indexingBlocked = document.querySelector('[data-indexing-blocked]');
    const details = document.querySelector('[data-import-queue-files]');
    const count = document.querySelector('[data-import-queue-count]');
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
            const {screening, embedding, indexed, skipped, blocked = 0} = status.indexing;
            const prepared = indexed + skipped;
            const passageTotal = prepared + screening + embedding + blocked;
            const indexingText = `${prepared} of ${passageTotal} passages processed` + (blocked ? ` · ${blocked} blocked` : '');
            const indexingRevision = [status.indexingRevision, indexed, skipped];
            if (blocked) indexingRevision.push(blocked);
            updateProgress.files(status.finished, status.total, text, JSON.stringify([status.latestBatch, status.total]));
            updateProgress.indexing(prepared, passageTotal, indexingText, JSON.stringify(indexingRevision), blocked);
            updateVisibility();

            if (details) {
                count.textContent = text;
                indexingBlocked.hidden = blocked === 0;
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
