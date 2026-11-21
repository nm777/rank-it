@extends('layout')
@section('title', 'Ask my memory')
@section('content')
<a href="{{ route('memories.index') }}">My memories</a>
<h1>{{ $question }}</h1>
@if (!$results)
    <p>I found no matching passages in your memories.</p>
@else
    @if ($answer === null)
        <p>Answer generation is unavailable.</p>
    @elseif (!$answer['claims'])
        <p>The matching passages do not establish an answer.</p>
    @else
        @foreach ($answer['claims'] as $claim)
            <article>“{{ $claim['quote'] }}” @include('source-link', ['source' => $results[$claim['key']], 'excerpt' => $claim['quote']])</article>
        @endforeach
        @if ($answer['partial'])
            <p>The passages support only part of your question.@if ($answer['missing']) Missing evidence for “{{ $answer['missing'] }}”.@endif</p>
        @endif
    @endif
    @if (empty($answer['claims']))
        <h2>Search results</h2>
        @foreach ($results as $source)
            <article>{{ \Illuminate\Support\Str::limit($source->wording, 200) }} @include('source-link')</article>
        @endforeach
    @endif
@endif
@endsection
