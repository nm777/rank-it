@extends('layout')
@section('title', 'Memory')
@section('content')
<a href="{{ route('memories.index') }}">My memories</a>
<h1>Memory</h1>
@if (session('status'))
    <p role="status">{{ session('status') }}</p>
@endif
<p class="wording">{{ $memory->wording }}</p>
<p>Captured {{ $memory->created_at->toDateString() }} · Experience {{ $memory->experience_date ?? 'date unknown' }}</p>
<details @if($errors->any()) open @endif>
    <summary>Correct memory</summary>
    <form method="post" action="{{ route('memories.update', $memory) }}" class="stack">
        @csrf
        @method('PATCH')
        <label for="wording">Wording</label>
        <textarea id="wording" name="wording" rows="5" maxlength="10000" required>{{ "\n" }}{{ old('wording', $memory->wording) }}</textarea>
        @include('experience-date', ['experienceDate' => $memory->experience_date])
        <button>Save correction</button>
    </form>
</details>
@endsection
