<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Rank It</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<header>
    <a class="brand" href="{{ route('memories.index') }}">Rank It</a>
    @auth
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button class="secondary">Sign out</button>
        </form>
    @endauth
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
</main>
</body>
</html>
