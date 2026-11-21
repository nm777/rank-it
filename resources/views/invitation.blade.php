@extends('layout')
@section('title', 'Accept invitation')
@section('content')
<h1>Accept invitation</h1>
<p>Create an account for <strong>{{ $email }}</strong>.</p>
<form method="post" action="{{ route('invitation.redeem', $token) }}" class="stack">
    @csrf
    <label>Name <input name="name" value="{{ old('name') }}" autocomplete="name" required></label>
    <label>Password <input name="password" type="password" autocomplete="new-password" minlength="12" required>
        <small>Use at least 12 characters.</small>
    </label>
    <label>Confirm password <input name="password_confirmation" type="password" autocomplete="new-password" required></label>
    <button>Create account</button>
</form>
@endsection
