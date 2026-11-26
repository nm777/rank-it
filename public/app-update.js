(() => {
    const banner = document.querySelector('.app-update');
    const siteMenu = document.querySelector('.site-menu');
    let checking = false;

    function reloadApp() {
        if (siteMenu) {
            siteMenu.open = false;
        }
        window.location.reload();
    }

    document.querySelectorAll('[data-reload-app]').forEach(button => {
        button.addEventListener('click', reloadApp);
    });

    async function checkVersion() {
        if (document.hidden || !banner.hidden || checking) {
            return;
        }

        checking = true;
        try {
            const response = await fetch(banner.dataset.versionUrl, {
                cache: 'no-store',
                signal: AbortSignal.timeout(10000),
            });
            if (!response.ok) {
                return;
            }

            const { version } = await response.json();
            if (typeof version === 'string' && /^[a-f0-9]{64}$/.test(version) && version !== banner.dataset.appVersion) {
                banner.hidden = false;
            }
        } catch {
            // Retry on the next check if offline or the server is unavailable.
        } finally {
            checking = false;
        }
    }

    document.addEventListener('visibilitychange', checkVersion);
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            checkVersion();
        }
    });
    window.setInterval(checkVersion, 60000);
    checkVersion();
})();
