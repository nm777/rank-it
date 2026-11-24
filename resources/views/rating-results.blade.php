@extends('layout')
@section('title', 'Ratings')
@section('content')
<a href="{{ route('memories.index') }}">My memories</a>
<h1>{{ $question }}</h1>
<p>{{ count($confirmed) }} confirmed {{ Str::plural('rating', count($confirmed)) }} found.</p>
@if ($partial)<p>Ratings cover only the processed portion of your imports.</p>@endif
@foreach ([$confirmed, $uncertain] as $rows)
@if ($loop->last && $rows)<h2>Uncertain dates</h2><p>These ratings are excluded from the confirmed count.</p>@endif
@foreach ($rows as $row)
<article>{{ $row->identified_person ?? $row->subject }} {{ $row->aspect }} · {{ $row->score }}{{ $row->scale !== null ? '/'.$row->scale : ' (scale unknown)' }}
 · {{ $row->mine ? 'You' : ($row->sender ?: 'Unknown author') }} · Rated {{ $row->created_at ?? $row->sent_date ?? 'date unknown' }} · Experience {{ $row->experience ?? 'date unknown' }}
@if ($row->memory_id)<a href="{{ route('memories.show', [$row->memory_id, 'highlight' => 1]) }}#passage">Original wording</a>
@else<a href="{{ route('passages.show', $row->passage_id) }}#passage-{{ $row->passage_id }}">Original score</a>
@if ($row->reply)<a href="{{ route('passages.show', $row->reply) }}#passage-{{ $row->reply }}">Agreement</a>@endif
@endif</article>
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
@endsection
