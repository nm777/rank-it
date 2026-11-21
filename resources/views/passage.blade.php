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
@endsection
