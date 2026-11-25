@extends('layout')
@section('title', 'Profile')
@section('content')
<p><a href="{{ route('profile.usage') }}">AI usage</a></p>
<h1>Profile</h1>
@if (session('status'))
    <p role="status">{{ session('status') }}</p>
@endif
<form method="post" action="{{ route('profile.update') }}" class="stack">
    @csrf
    @method('PATCH')
    <label>Name <input name="name" value="{{ is_string(old('name')) ? old('name') : $user->name }}" autocomplete="name" maxlength="255" required></label>
    <label>Email <input name="email" type="email" value="{{ is_string(old('email')) ? old('email') : $user->email }}" autocomplete="username" maxlength="255" required></label>
    <label>Current password <input name="current_password" type="password" autocomplete="current-password" required></label>
    <button>Save profile</button>
</form>

<h2>Change password</h2>
<form method="post" action="{{ route('profile.password') }}" class="stack">
    @csrf
    @method('PUT')
    <label>Current password <input name="current_password" type="password" autocomplete="current-password" required></label>
    <label>New password <input name="password" type="password" autocomplete="new-password" minlength="12" required></label>
    <label>Confirm new password <input name="password_confirmation" type="password" autocomplete="new-password" required></label>
    <button>Update password</button>
</form>
@endsection
