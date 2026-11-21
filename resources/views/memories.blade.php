@extends('layout')
@section('title', 'My memories')
@section('content')
<h1>My memories</h1>
<form method="post" action="{{ route('memories.store') }}" class="stack">
    @csrf
    <label for="wording">Write or dictate a memory, or enter words to find</label>
    <textarea id="wording" name="wording" rows="5" maxlength="10000" required>{{ "\n" }}{{ old('wording', $query ?? '') }}</textarea>
    @include('experience-date', ['experienceDate' => null])
    <div class="actions">
        <button>Remember this</button>
        <button class="secondary" formaction="{{ route('memories.search') }}">Find wording</button>
    </div>
</form>
<section aria-labelledby="memory-list-heading">
    <h2 id="memory-list-heading">{{ isset($query) ? 'Search results' : 'Recent memories' }}</h2>
    @forelse ($results as $memory)
        <article>
            <a href="{{ route('memories.show', $memory) }}">{{ \Illuminate\Support\Str::limit($memory->wording, 120) }}</a>
            <small>Saved {{ $memory->created_at->toDateString() }}</small>
        </article>
    @empty
        <p>{{ isset($query) ? 'No matching memories.' : 'Your saved memories will appear here.' }}</p>
    @endforelse
</section>
@endsection
