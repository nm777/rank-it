@extends('layout')
@section('title', 'AI usage')
@section('content')
@include('profile-tabs')
@if ($usage->calls)
    <p>Estimated total cost <strong>${{ number_format($usage->cost / 1_000_000_000, $usage->cost >= 10_000_000 || $usage->cost == 0 ? 2 : 9) }} USD</strong></p>
    <p>{{ number_format($usage->calls) }} recorded AI {{ Str::plural('call', $usage->calls) }}</p>
@else
    <p>No AI usage recorded for your account yet.</p>
@endif
<p>The estimate uses recorded provider usage and configured rates. It covers questions and background indexing since account-level tracking began.</p>
@endsection
