@extends('layout')
@section('title', 'Memory')
@section('content')
<a href="{{ route('memories.index') }}">My memories</a>
<h1>Memory</h1>
@if (session('status'))
    <p role="status">{{ session('status') }}</p>
@endif
<p class="wording" id="passage">@if(request()->boolean('highlight'))@include('highlight', ['wording' => $memory->wording])@else{{ $memory->wording }}@endif</p>
<p>Author: You · Captured {{ $memory->created_at->toDateString() }} · Experience {{ $memory->experience_date ?? 'date unknown' }}</p>
@foreach ($ratings as $rating)
<p>{{ $rating->subject }} {{ $rating->aspect }} · {{ $rating->score }}{{ $rating->scale !== null ? '/'.$rating->scale : ' (scale unknown)' }}</p>
@endforeach
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
<details><summary>Identify a person in this memory</summary>
<form method="post" action="{{ route('memories.person', $memory) }}">@csrf
@if ($ratings->pluck('subject')->unique()->count() > 1)
<label>Mention <select name="subject">@foreach ($ratings->pluck('subject')->unique() as $subject)<option @selected($subject === $memory->identified_subject)>{{ $subject }}</option>@endforeach</select></label>
@endif
<label>Person <input name="person" required maxlength="255" value="{{ $memory->identified_person }}"></label>
<button>Save identity link</button></form></details>
@endsection
