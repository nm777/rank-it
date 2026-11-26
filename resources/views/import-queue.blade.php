@extends('layout')
@section('title', 'Import queue')
@section('content')
<h1>Import queue</h1>
<p data-import-queue-count>{{ $finished }} of {{ $total }} files processed</p>
<div data-import-queue-files>
    @include('import-queue-files')
</div>
<p><a href="{{ route('imports.index') }}">Back to imports</a></p>
@endsection
