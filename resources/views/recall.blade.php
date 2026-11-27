@extends('layout')
@section('title', 'Ask my memory')
@section('content')
@include('question-header')
<section id="answer" class="answer-results" aria-label="Answer">
@if (!$answerAvailable)
    <p>Answer generation is unavailable. You can ask again later.</p>
@endif
@if (!$results)
    <p>I found no matching passages in your memories.</p>
@else
    @if (count($answer['clarification'] ?? []) > 1)
        <p>Which person did you mean?</p>
        @foreach ($answer['clarification'] as $key)
            <x-memory-card>{{ $results[$key]->identified_person ?: Str::limit($results[$key]->wording, 180) }} @include('source-link', ['source' => $results[$key]])</x-memory-card>
        @endforeach
    @elseif ($answer !== null && !$answer['claims'] && !$answer['uncertain'])
        <p>The matching passages do not establish an answer.</p>
    @elseif ($answer !== null)
        @if (isset($answer['summary']))
            <p class="answer-wording">{{ $answer['summary'] }}</p>
        @endif
        @if ($answer['conflict'])<p>The sources give conflicting accounts.</p>@endif
        @if (preg_match('/\b(?:how many|count|list|all|every)\b/i', $question))
            <p>@if ($answer['confirmedCount'] !== null){{ $answer['confirmedCount'] }} confirmed {{ Str::plural('match', $answer['confirmedCount']) }} found. @endif Search coverage may be incomplete.</p>
        @endif
        @if (isset($answer['summary']))
            <h2>Supporting passages</h2>
        @endif
        @foreach ($answer['claims'] as $claim)
            <x-memory-card>“{{ $claim['quote'] }}”<x-slot:actions>@include('source-link', ['source' => $results[$claim['key']], 'excerpt' => $claim['quote']])@include('dismiss-source', ['key' => $claim['key']])</x-slot:actions></x-memory-card>
        @endforeach
        @if ($answer['partial'])
            <p class="answer-note">The passages support only part of your question.@if ($answer['missing']) Missing evidence for “{{ $answer['missing'] }}”.@endif</p>
        @endif
    @endif
    @if (!empty($answer['uncertain']))
        <h2>Uncertain matches</h2>
        @foreach ($answer['uncertain'] as $claim)
            <x-memory-card>{{ $claim['quote'] }} @include('source-link', ['source' => $results[$claim['key']], 'excerpt' => $claim['quote']])</x-memory-card>
        @endforeach
    @endif
    @if (empty($answer['claims']) && empty($answer['uncertain']) && count($answer['clarification'] ?? []) < 2)
        <h2>Search results</h2>
        @foreach ($results as $source)
            <x-memory-card>{{ \Illuminate\Support\Str::limit($source->wording, 200) }}<x-slot:actions>@include('source-link') @include('dismiss-source', ['key' => $source->key])</x-slot:actions></x-memory-card>
        @endforeach
    @endif
@endif
</section>
@include('conversation-controls')
@endsection
