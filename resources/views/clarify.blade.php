@extends('layout')
@section('title', 'Clarify')
@section('content')
<h1>Save this as a memory or ask about it?</h1>
<form method="post" action="{{ route('memories.input') }}" class="stack">
    @csrf
    <label for="wording">Your wording</label>
    <textarea name="wording" id="wording" required>{{ "\n" }}{{ $wording }}</textarea>
    <input type="hidden" name="experience_date" value="{{ $experience_date }}">
    <div class="actions"><button name="intent" value="save">Remember this</button>
        <button name="intent" value="ask">Ask my memory</button></div>
</form>
@endsection
