(() => {
    const dialog = document.querySelector('[data-document-original-dialog]');
    if (navigator.standalone !== true || !dialog?.showModal || !navigator.share || !navigator.canShare) return;

    const status = dialog.querySelector('[data-original-status]');
    const shareButton = dialog.querySelector('[data-original-share]');
    const fallback = dialog.querySelector('[data-original-fallback]');
    let request;
    let original;

    function filename(disposition) {
        const encoded = /filename\*=UTF-8''([^;]+)/i.exec(disposition)?.[1];
        if (encoded) return decodeURIComponent(encoded);
        return /filename="([^"]+)"|filename=([^;]+)/i.exec(disposition)?.slice(1).find(Boolean)?.trim();
    }

    document.addEventListener('click', async (event) => {
        const link = event.target.closest('[data-document-original]');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        event.preventDefault();
        const pending = new AbortController();
        request = pending;
        original = null;
        shareButton.hidden = true;
        fallback.hidden = true;
        fallback.href = link.href;
        status.textContent = 'Preparing the original file…';
        dialog.showModal();

        try {
            const response = await fetch(link.href, {signal: pending.signal, redirect: 'error'});
            const name = filename(response.headers.get('Content-Disposition') ?? '');
            if (!response.ok || !name) throw new Error('Original unavailable');
            const blob = await response.blob();
            if (pending.signal.aborted) return;
            const type = /\.pdf$/i.test(name) ? 'application/pdf' : blob.type;
            const file = new File([blob], name, {type});
            if (!navigator.canShare({files: [file]})) {
                status.textContent = 'This file can be downloaded in a separate window.';
                fallback.hidden = false;
                return;
            }
            original = file;
            status.textContent = name;
            shareButton.hidden = false;
        } catch {
            if (!pending.signal.aborted) status.textContent = 'Could not load the original file. Close and try again.';
        }
    });

    shareButton.addEventListener('click', async () => {
        if (!original) return;
        const pending = request;
        shareButton.disabled = true;

        try {
            await navigator.share({files: [original]});
            if (request === pending && dialog.open) dialog.close();
        } catch (error) {
            if (request === pending && dialog.open && error.name !== 'AbortError') {
                status.textContent = 'Could not share the original file. Try again or download it in a separate window.';
                fallback.hidden = false;
            }
        } finally {
            shareButton.disabled = false;
        }
    });

    dialog.addEventListener('close', () => {
        request?.abort();
        original = null;
        fallback.removeAttribute('href');
    });
})();
