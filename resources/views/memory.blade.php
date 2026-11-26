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
<section aria-labelledby="memory-photos">
    <h2 id="memory-photos">Photos</h2>
    @foreach ($attachments as $attachment)
        <figure class="memory-photo-item">
            <a href="{{ route('attachments.show', [$memory, $attachment]) }}">
                <img class="memory-photo" alt="Photo {{ $loop->iteration }} for this memory" loading="lazy" src="{{ route('attachments.show', [$memory, $attachment]) }}">
            </a>
            <form method="post" action="{{ route('attachments.delete', [$memory, $attachment]) }}">
                @csrf
                @method('DELETE')
                <button class="text-action" aria-label="Remove photo {{ $loop->iteration }}">Remove photo</button>
            </form>
        </figure>
    @endforeach
    @if ($attachments->count() < \App\MemoryPhoto::MAX_PER_MEMORY)
        <form method="post" enctype="multipart/form-data" action="{{ route('attachments.store', $memory) }}" class="stack">
            @csrf
            <x-photo-picker :required="true">
                <button type="submit">Save photo</button>
            </x-photo-picker>
        </form>
    @endif
</section>
<details id="correct-memory"@if ($errors->hasAny(['wording', 'experience_date']) || request()->boolean('edit')) open @endif>
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
<details id="identify-person" @if(request()->filled('person')) open @endif><summary>Identify a person in this memory</summary>
<form method="post" action="{{ route('memories.person', $memory) }}">@csrf
@if ($ratings->pluck('subject')->unique()->count() > 1)
<label>Mention <select name="subject">@foreach ($ratings->pluck('subject')->unique() as $subject)<option @selected($subject === $memory->identified_subject)>{{ $subject }}</option>@endforeach</select></label>
@endif
<label>Person <input name="person" required maxlength="255" value="{{ is_string(request('person')) ? request('person') : $memory->identified_person }}"></label>
<button>Save identity link</button></form></details>
@if ($memory->corrects_passage_id)<p>Correction to <a href="{{ route('passages.show', $memory->corrects_passage_id) }}">original passage</a></p>@endif
<form method="post" action="{{ route('memories.delete', $memory) }}">@csrf @method('DELETE')<button>Delete memory</button></form>
@endsection
