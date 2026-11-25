@extends('layout')
@section('title', 'Ratings')
@section('content')
@include('question-header')
<section id="answer" class="answer-results" aria-label="Answer">
<p>{{ count($confirmed) }} confirmed {{ Str::plural('rating', count($confirmed)) }} found.</p>
@if ($partial)<p>Ratings cover only the processed portion of your imports.</p>@endif
@foreach ([$confirmed, $uncertain] as $rows)
@if ($loop->last && $rows)<h2>Uncertain dates</h2><p>These ratings are excluded from the confirmed count.</p>@endif
@foreach ($rows as $row)
<x-memory-card>{{ $row->identified_person ?? $row->subject }} {{ $row->aspect }} · {{ $row->score }}{{ $row->scale !== null ? '/'.$row->scale : ' (scale unknown)' }}
 · {{ $row->mine ? 'You' : ($row->sender ?: 'Unknown author') }} · Rated {{ $row->created_at ?? $row->sent_date ?? 'date unknown' }} · Experience {{ $row->experience ?? 'date unknown' }}<x-slot:actions>
@if ($row->memory_id)<a href="{{ route('memories.show', [$row->memory_id, 'highlight' => 1]) }}#passage">Original wording</a>
@else<a href="{{ route('passages.show', $row->passage_id) }}#passage-{{ $row->passage_id }}">Original score</a>
@if ($row->reply)<a href="{{ route('passages.show', $row->reply) }}#passage-{{ $row->reply }}">Agreement</a>@endif
@endif @include('dismiss-source', ['key' => $row->memory_id ? 'memory:'.$row->memory_id : 'passage:'.$row->passage_id])</x-slot:actions></x-memory-card>
@endforeach
@endforeach
@foreach ($identities as $row)
<form method="post" action="{{ route('messages.identity', $row->message_id) }}">@csrf
<input type="hidden" name="wording" value="{{ $question }}">
<p>Did you write <a href="{{ route('passages.show', $row->reply ?? $row->passage_id) }}">this message</a> from {{ $row->sender ?: 'an unknown sender' }}?</p>
<button name="scope" value="message">Yes, this message</button>
@if ($row->address)<button name="scope" value="address">Yes, {{ $row->address }} is my personal address</button>@endif
<button name="scope" value="other">Someone else wrote this</button></form>
@endforeach
</section>
@include('conversation-controls')
@endsection
