@extends('layout')
@section('title', 'My memories')
@section('page-class', 'memory-entry')
@section('content')
<form method="post" action="{{ route('memories.input') }}" class="stack entry-form">
    @csrf
    <label for="wording">Write a memory or ask a question</label>
    <textarea id="wording" name="wording" rows="3" enterkeyhint="send" maxlength="10000" required @if (!isset($query)) autofocus @endif>{{ "\n" }}{{ old('wording', $query ?? '') }}</textarea>
    <button type="submit">Submit</button>
</form>
<script>
document.querySelector('#wording').addEventListener('keydown', event => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;

    event.preventDefault();
    if (!event.repeat) event.currentTarget.form.requestSubmit();
});
</script>
@if (isset($savedMemory))
    @include('replay-input', ['replayWording' => $savedMemory->wording, 'replayDate' => $savedMemory->experience_date])
@endif
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
