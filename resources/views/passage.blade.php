@extends('layout')
@section('title', $isDocument ? $message->subject : 'Mailbox passage')
@section('content')
@if ($isDocument)
<a href="{{ route('memories.browse') }}#documents">‹ Documents</a>
@endif
<h1>{{ $message->subject ?: 'Mailbox message' }}</h1>
@if ($hasOriginal)
    <p><a href="{{ route('documents.original', $message->id) }}">Download original</a></p>
@elseif ($isDocument)
    <form method="post" enctype="multipart/form-data" action="{{ route('imports.store') }}" class="stack">
        @csrf
        <input type="hidden" name="restore_message" value="{{ $message->id }}">
        <label>Original file <input type="file" name="mailbox" required></label>
        <button>Restore original</button>
    </form>
@endif
@if (! $isDocument)
<p>{{ $message->sender ?: 'Unknown sender' }} · {{ $message->sent_date ?: 'Unknown date' }}</p>
@endif
<article @class(['document-reader' => $isDocument, 'document-spreadsheet' => $isSpreadsheet])>
@foreach ($passages as $part)
    @if ($part->quote_text !== null)
        <small>Quoted history @if ($part->source_id) · <a href="{{ route('passages.show', $part->source_id) }}">Original passage</a>@endif</small>
    @endif
    <div class="passage-text" id="passage-{{ $part->id }}">@if ($part->id === $selected->id)@include('highlight', ['wording' => $part->wording, 'highlightWholePassage' => ! $isDocument])@else{{ $part->wording }}@endif</div>
@endforeach
</article>
@if ($skipped)<form method="post" action="{{ route('passages.promote', $selected->id) }}">@csrf<button>Remember this</button></form>@endif
<form method="post" action="{{ route('messages.delete', $message->id) }}">@csrf @method('DELETE')<button>{{ $isDocument ? 'Delete document' : 'Delete message from all batches' }}</button></form>
<details><summary>Correct this passage</summary>
<form method="post" action="{{ route('passages.correct', $selected->id) }}">@csrf
<label>Your correction <textarea name="wording" maxlength="10000" required></textarea></label>
@include('experience-date', ['experienceDate' => null])
<button>Save correction as a memory</button></form></details>
@endsection
