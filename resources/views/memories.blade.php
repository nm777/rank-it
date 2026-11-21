@extends('layout')
@section('title', 'My memories')
@section('content')
<h1>My memories</h1>
<form method="post" action="{{ route('memories.input') }}" class="stack">
    @csrf
    <label for="wording">Write a memory or ask a question</label>
    <textarea id="wording" name="wording" rows="5" maxlength="10000" required>{{ "\n" }}{{ old('wording', $query ?? '') }}</textarea>
    @include('experience-date', ['experienceDate' => null])
    <div class="actions">
        <button>Continue</button>
        <button class="secondary" formaction="{{ route('memories.search') }}">Find wording</button>
    </div>
</form>
<section aria-labelledby="memory-list-heading">
    <h2 id="memory-list-heading">{{ isset($query) ? 'Search results' : 'Recent memories' }}</h2>
    @foreach ($results as $memory)
        <article>
            <a href="{{ route('memories.show', $memory) }}">{{ \Illuminate\Support\Str::limit($memory->wording, 120) }}</a>
            <small>Saved {{ $memory->created_at->toDateString() }}</small>
        </article>
    @endforeach
    @foreach ($passages ?? [] as $passage)
        <article><a href="{{ route('passages.show', $passage->id) }}#passage-{{ $passage->id }}">{{ \Illuminate\Support\Str::limit($passage->wording, 120) }}</a>
            <small>{{ $passage->quote_text !== null ? 'Quoted history in message from ' : '' }}{{ $passage->sender ?: 'Unknown sender' }} · {{ $passage->sent_date ?: 'Unknown date' }}</small>
        </article>
    @endforeach
    @if ($results->isEmpty() && ($passages ?? collect())->isEmpty())
        <p>{{ isset($query) ? 'No matching memories.' : 'Your saved memories will appear here.' }}</p>
    @endif
</section>
<a href="{{ route('imports.index') }}">Mailbox imports</a>
@endsection
