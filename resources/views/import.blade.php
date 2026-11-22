@extends('layout')
@section('title', 'Mailbox import')
@section('content')
<h1>{{ $import->filename }}</h1>
@if (session('status')) <p role="status">{{ session('status') }}</p> @endif
<p>{{ $import->status }}</p>
<p>{{ $import->imported }} messages imported · {{ $import->unreadable }} known unreadable</p>
@if ($import->excluded)<p>{{ $import->excluded }} previously deleted messages skipped</p>@endif
@if ($pending)<p>Text search is ready. Meaning-based search is pending.</p>@endif
@if ($attachments->isNotEmpty())
    <h2>Skipped attachments</h2>
    <ul>@foreach ($attachments as $filename)<li>{{ $filename }}</li>@endforeach</ul>
@endif
@if ($import->unreadable)<p>Upload a repaired export or convert unreadable material to a supported format.</p>@endif
@if ($files->count() > 1)
<h2>Files in this batch</h2>
@foreach ($files as $file)<p><a href="{{ route('imports.show', $file->id) }}">{{ $file->filename }}</a> · {{ $file->status }}</p>@endforeach
@endif
@foreach ($qualified as $id)<p><a href="{{ route('passages.show', $id) }}">Open newly qualifying passage {{ $id }}</a></p>@endforeach
<form method="post" action="{{ route('imports.remove', $import->batch_id) }}">@csrf @method('DELETE')<button>Remove this batch</button></form>
<a href="{{ route('imports.index') }}">Mailbox imports</a>
@endsection
