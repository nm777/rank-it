@extends('layout')
@section('title', 'Mailbox import')
@section('content')
<h1>{{ $import->filename }}</h1>
@if (session('status')) <p role="status">{{ session('status') }}</p> @endif
<p>{{ $import->status }}</p>
@if ($import->status === 'Multiple matching documents')
    <p>Open the intended document in Memories and choose Restore original.</p>
@elseif ($import->status === 'Original does not match')
    <p>Restore requires the same filename and extracted text, and an entry with no original file.</p>
@elseif ($import->status === 'Matched existing document')
    <p>The original is available in <a href="{{ route('memories.browse', ['q' => $import->filename]) }}#documents">Documents</a>.</p>
@endif
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
<details>
    <summary>Remove this batch</summary>
    <p>This removes {{ $files->count() }} {{ Str::plural('upload', $files->count()) }}, {{ $removal['passages'] }} searchable {{ Str::plural('passage', $removal['passages']) }}, and {{ $removal['generatedMemories'] }} generated {{ Str::plural('memory', $removal['generatedMemories']) }}. Sources shared with another batch are kept.</p>
    <form method="post" action="{{ route('imports.remove', $import->batch_id) }}">@csrf @method('DELETE')<button>Confirm batch removal</button></form>
</details>
<a href="{{ route('imports.index') }}">Mailbox imports</a>
@endsection
