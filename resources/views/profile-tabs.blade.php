<nav class="profile-tabs" aria-label="Account">
    <a href="{{ route('profile.show') }}" @if(request()->routeIs('profile.show')) aria-current="page" @endif>Profile</a>
    <a href="{{ route('profile.usage') }}" @if(request()->routeIs('profile.usage')) aria-current="page" @endif>AI Usage</a>
</nav>
