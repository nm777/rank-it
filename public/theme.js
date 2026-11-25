(() => {
    const modes = ['light', 'dark', 'auto'];
    const labels = { light: 'Light', dark: 'Dark', auto: 'Auto' };
    const storageKey = 'rank-it-theme';
    const root = document.documentElement;
    let mode = 'auto';

    try {
        const savedMode = localStorage.getItem(storageKey);
        if (modes.includes(savedMode)) {
            mode = savedMode;
        }
    } catch {
        // The switch still works in this page when browser storage is unavailable.
    }

    root.dataset.theme = mode;

    document.addEventListener('DOMContentLoaded', () => {
        const switcher = document.querySelector('#theme-switcher');
        if (!switcher) {
            return;
        }

        function renderSwitcher() {
            const nextMode = modes[(modes.indexOf(mode) + 1) % modes.length];
            switcher.textContent = `Theme: ${labels[mode]}`;
            switcher.setAttribute('aria-label', `Theme: ${labels[mode]}. Switch to ${labels[nextMode]}.`);
            return nextMode;
        }

        let nextMode = renderSwitcher();
        switcher.hidden = false;
        switcher.addEventListener('click', () => {
            mode = nextMode;
            root.dataset.theme = mode;
            nextMode = renderSwitcher();

            try {
                localStorage.setItem(storageKey, mode);
            } catch {
                // Storage can be disabled even when JavaScript is enabled.
            }
        });
    });
})();
