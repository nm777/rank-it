@extends('layout')
@section('title', 'Mailbox import')
@section('content')
<h1>{{ $import->filename }}</h1>
@if (session('status')) <p role="status">{{ session('status') }}</p> @endif
<p>{{ $import->status }}</p>
<p>{{ $import->imported }} messages imported · {{ $import->unreadable }} known unreadable</p>
@if ($pending)<p>Text search is ready. Meaning-based search is pending.</p>@endif
@if ($attachments->isNotEmpty())
    <h2>Skipped attachments</h2>
    <ul>@foreach ($attachments as $filename)<li>{{ $filename }}</li>@endforeach</ul>
@endif
<a href="{{ route('imports.index') }}">Mailbox imports</a>
@endsection
