@extends('layout')
@section('title', 'Mailbox passage')
@section('content')
<h1>{{ $message->subject ?: 'Mailbox message' }}</h1>
<p>{{ $message->sender ?: 'Unknown sender' }} · {{ $message->sent_date ?: 'Unknown date' }}</p>
@foreach ($passages as $part)
    @if ($part->quote_text !== null)
        <small>Quoted history @if ($part->source_id) · <a href="{{ route('passages.show', $part->source_id) }}">Original passage</a>@endif</small>
    @endif
    <div style="white-space: pre-wrap; overflow-wrap: anywhere" id="passage-{{ $part->id }}">@if ($part->id === $selected->id)@include('highlight', ['wording' => $part->wording])@else{{ $part->wording }}@endif</div>
@endforeach
@if ($skipped)<form method="post" action="{{ route('passages.promote', $selected->id) }}">@csrf<button>Remember this</button></form>@endif
<form method="post" action="{{ route('messages.delete', $message->id) }}">@csrf @method('DELETE')<button>Delete message from all batches</button></form>
<details><summary>Correct this passage</summary>
<form method="post" action="{{ route('passages.correct', $selected->id) }}">@csrf
<label>Your correction <textarea name="wording" maxlength="10000" required></textarea></label>
@include('experience-date', ['experienceDate' => null])
<button>Save correction as a memory</button></form></details>
@endsection
