@extends('layout')
@section('title', 'Profile')
@section('content')
@include('profile-tabs')
@if (session('status'))
    <p role="status">{{ session('status') }}</p>
@endif
<form id="profile-form" method="post" action="{{ route('profile.update') }}" class="stack">
    @csrf
    @method('PATCH')
    <label>Name <input name="name" value="{{ is_string(old('name')) ? old('name') : $user->name }}" autocomplete="name" maxlength="255" required></label>
    <label>Email <input name="email" type="email" value="{{ is_string(old('email')) ? old('email') : $user->email }}" data-saved-email="{{ $user->email }}" autocomplete="username" maxlength="255" required></label>
    <label>Current password (required to change your email) <input name="current_password" type="password" autocomplete="current-password"></label>
    <button>Save profile</button>
</form>

<form action="{{ route('profile.password.show') }}">
    <p><button class="secondary">Change password</button></p>
</form>
<script>
    const profileForm = document.getElementById('profile-form');
    const profileEmail = profileForm.elements.email;
    const profilePassword = profileForm.elements.current_password;

    function syncEmailPasswordField() {
        const changingEmail = profileEmail.value.trim().toLowerCase() !== profileEmail.dataset.savedEmail.toLowerCase();
        profilePassword.closest('label').hidden = !changingEmail;
        profilePassword.disabled = !changingEmail;
        profilePassword.required = changingEmail;
    }

    profileEmail.addEventListener('input', syncEmailPasswordField);
    profileEmail.addEventListener('change', syncEmailPasswordField);
    window.addEventListener('pageshow', syncEmailPasswordField);
    syncEmailPasswordField();
</script>
@endsection
