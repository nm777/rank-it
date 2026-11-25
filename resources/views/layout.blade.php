<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Rank It</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
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
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </nav>
        </details>
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
    <a class="brand" href="{{ route('memories.index') }}">Rank It</a>
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
