@extends('layout')
@section('title', 'Change password')
@section('content')
@include('profile-tabs')
<h1>Change password</h1>
<form method="post" action="{{ route('profile.password') }}" class="stack">
    @csrf
    @method('PUT')
    <label>Current password <input name="current_password" type="password" autocomplete="current-password" required></label>
    <label>New password <input name="password" type="password" autocomplete="new-password" minlength="12" required></label>
    <label>Confirm new password <input name="password_confirmation" type="password" autocomplete="new-password" required></label>
    <button>Update password</button>
</form>
@endsection
