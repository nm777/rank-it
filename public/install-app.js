(() => {
    const installButton = document.querySelector('#install-app');
    const installHelp = document.querySelector('#install-help');
    const siteMenu = installButton.closest('.site-menu');
    const menuToggle = siteMenu.querySelector('summary');
    const userAgent = navigator.userAgent;
    const isAppleMobile = /iPad|iPhone|iPod/.test(userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/.test(userAgent);
    const standalone = window.matchMedia('(display-mode: standalone)');
    let installPrompt;
    let installed = false;

    function updateInstallButton() {
        installButton.hidden = installed || standalone.matches || navigator.standalone === true
            || !(isAppleMobile || isAndroid || installPrompt);
    }

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
        updateInstallButton();
    });

    window.addEventListener('appinstalled', () => {
        installed = true;
        installPrompt = undefined;
        updateInstallButton();
    });

    standalone.addEventListener('change', updateInstallButton);

    installButton.addEventListener('click', async () => {
        siteMenu.open = false;
        menuToggle.focus();

        if (installPrompt) {
            const prompt = installPrompt;
            installPrompt = undefined;
            updateInstallButton();

            try {
                const { outcome } = await prompt.prompt();
                if (outcome === 'accepted') {
                    installed = true;
                    updateInstallButton();
                }
                return;
            } catch {
                // A browser that declines to open its prompt still has manual install steps.
            }
        }

        installHelp.showModal();
    });

    installHelp.querySelector('[data-install-platform="ios"]').hidden = isAndroid;
    installHelp.querySelector('[data-install-platform="android"]').hidden = isAppleMobile;
    updateInstallButton();
})();
