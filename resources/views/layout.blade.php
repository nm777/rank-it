<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#02274e">
    <title>@yield('title') · Rank It</title>
    <script src="{{ asset('theme.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
    <link rel="manifest" href="{{ asset('rank-it.webmanifest') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('rank-it-192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('rank-it-180.png') }}">
    @auth
        <script src="{{ asset('install-app.js') }}" defer></script>
    @endauth
</head>
<body class="@yield('page-class')">
<header>
    @auth
        <details class="site-menu">
            <summary aria-label="Menu"><span aria-hidden="true"></span></summary>
            <nav aria-label="Main navigation">
                <a href="{{ route('memories.index') }}">Home</a>
                <a href="{{ route('memories.browse') }}">Memories</a>
                <a href="{{ route('imports.index') }}">Import</a>
                <button type="button" id="install-app" hidden>Add to Home Screen</button>
                <div class="theme-switcher" role="group" aria-label="Theme" hidden>
                    <button type="button" data-theme-mode="light" aria-label="Light" aria-pressed="false" title="Light">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <circle cx="12" cy="12" r="4"/>
                            <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>
                        </svg>
                    </button>
                    <button type="button" data-theme-mode="dark" aria-label="Dark" aria-pressed="false" title="Dark">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M20.5 15.2A9 9 0 0 1 8.8 3.5a9 9 0 1 0 11.7 11.7Z"/>
                        </svg>
                    </button>
                    <button type="button" data-theme-mode="auto" aria-label="Auto (system theme)" aria-pressed="false" title="Auto (system theme)">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="13" rx="2"/>
                            <path d="M12 17v3m-4 0h8"/>
                        </svg>
                    </button>
                </div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </nav>
        </details>
        <dialog class="install-dialog" aria-labelledby="install-title" id="install-help">
            <h2 id="install-title">Add Rank It to your Home Screen</h2>
            <p data-install-platform="ios"><strong>iPhone or iPad</strong> · Open Rank It in Safari, tap <strong>Share</strong> (under the page menu if needed), then <strong>Add to Home Screen</strong>. If shown, turn on <strong>Open as Web App</strong>, then tap <strong>Add</strong>.</p>
            <p data-install-platform="android"><strong>Android</strong> · Open Rank It in Chrome, tap the three-dot menu, then choose <strong>Install app</strong> or <strong>Install and create shortcut</strong>. Some versions call it <strong>Add to Home screen</strong>.</p>
            <form method="dialog">
                <button type="submit">Done</button>
            </form>
        </dialog>
        <script>
            const siteMenu = document.querySelector('.site-menu');
            const menuToggle = siteMenu.querySelector('summary');

            document.addEventListener('click', event => {
                if (!siteMenu.contains(event.target) || event.target.closest('.site-menu a')) {
                    siteMenu.open = false;
                }
            });

            siteMenu.addEventListener('keydown', event => {
                if (event.key === 'Escape' && siteMenu.open) {
                    siteMenu.open = false;
                    menuToggle.focus();
                }
            });
        </script>
    @endauth
    <a class="brand" href="{{ route('memories.index') }}">
        <img src="{{ asset('rank-it-180.png') }}" width="36" height="36" alt="">
        Rank It
    </a>
</header>
<main>
    @if ($errors->any())
        <ul class="errors" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
    @yield('content')
    @if (config('app.env') === 'local')
        @auth
            @php($aiOutput = \App\Inference\AiOutput::current() + session('ai_output', []))
            @if ($aiOutput)
                <details class="ai-output">
                    <summary>AI output</summary>
                    @foreach ($aiOutput as $step => $output)
                        <h2>{{ $step }}</h2>
                        <pre>{{ json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
                    @endforeach
                </details>
            @endif
        @endauth
    @endif
</main>
@if(session("evidence_changed"))<script>if (window.BroadcastChannel) { const channel = new BroadcastChannel("rank-it-evidence"); channel.postMessage("changed"); channel.close(); }</script>@endif
</body>
</html>
