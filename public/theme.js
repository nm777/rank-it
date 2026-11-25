(() => {
    const modes = ['light', 'dark', 'auto'];
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
        const switcher = document.querySelector('.theme-switcher');
        if (!switcher) {
            return;
        }

        const choices = switcher.querySelectorAll('[data-theme-mode]');
        function updateSelection() {
            for (const choice of choices) {
                choice.setAttribute('aria-pressed', String(choice.dataset.themeMode === mode));
            }
        }

        updateSelection();
        switcher.hidden = false;
        for (const choice of choices) {
            choice.addEventListener('click', () => {
                mode = choice.dataset.themeMode;
                root.dataset.theme = mode;
                updateSelection();

                try {
                    localStorage.setItem(storageKey, mode);
                } catch {
                    // Storage can be disabled even when JavaScript is enabled.
                }
            });
        }
    });
})();
