@extends('layout')
@section('title', 'Sign in')
@section('content')
<h1>Sign in</h1>
<form method="post" action="{{ route('login') }}" class="stack">
    @csrf
    <label>Email <input name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></label>
    <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
    <button>Sign in</button>
</form>
@endsection
