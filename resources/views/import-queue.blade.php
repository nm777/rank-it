@extends('layout')
@section('title', 'Import queue')
@section('content')
<h1>Import queue</h1>
<p data-import-queue-count>{{ $finished }} of {{ $total }} files processed</p>
<section>
    <h2>Meaning-based search</h2>
    <p>
        <span data-indexing-stage="screening">{{ $indexing['screening'] }}</span> awaiting screening ·
        <span data-indexing-stage="embedding">{{ $indexing['embedding'] }}</span> awaiting indexing ·
        <span data-indexing-stage="indexed">{{ $indexing['indexed'] }}</span> indexed ·
        <span data-indexing-stage="skipped">{{ $indexing['skipped'] }}</span> skipped
    </p>
</section>
<div data-import-queue-files>
    @include('import-queue-files')
</div>
<form class="stack" method="post" action="{{ route('memories.search') }}">
    @csrf
    <label for="import-search">Search imported text</label>
    <input type="search" id="import-search" name="wording" maxlength="10000" required>
    <p>Search for an exact phrase from a file. Imported files appear in search results as passages.</p>
    <button>Search</button>
</form>
<p><a href="{{ route('imports.index') }}">Back to imports</a></p>
@endsection
