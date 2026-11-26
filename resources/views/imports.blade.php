@extends('layout')
@section('title', 'Mailbox imports')
@section('content')
<h1>Mailbox imports</h1>
<form class="stack" method="post" enctype="multipart/form-data" action="{{ route('imports.store') }}">
    @csrf
    <label for="mailbox">Choose up to four EML, MBOX, DOCX, PDF, Excel (.xlsx or .xls), TXT or Markdown files (16 MB each)</label>
    <input type="file" name="files[]" multiple id="mailbox" required>
    <p>Importing makes documents and message bodies searchable in your private collection. PDFs need selectable text. Attachments are skipped.</p>
    <button>Import batch</button>
</form>
@foreach ($imports as $import)
    <article><a href="{{ route('imports.show', $import->id) }}">{{ $import->filename }}</a> · @if ($import->file_count > 1){{ $import->file_count }} files in batch @else{{ $import->status }}@endif</article>
@endforeach
{{ $imports->links() }}
@endsection
