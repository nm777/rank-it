@extends('layout')
@section('title', 'Mailbox imports')
@section('content')
<h1>Mailbox imports</h1>
<form id="import-batch-form" class="stack" method="post" enctype="multipart/form-data" action="{{ route('imports.store') }}">
    @csrf
    <label for="mailbox">Choose up to 100 EML, MBOX, DOCX, PDF, Excel (.xlsx or .xls), TXT or Markdown files (16 MB each)</label>
    <input type="file" name="files[]" multiple id="mailbox" required>
    <p>Importing makes documents and message bodies searchable in your private collection. PDFs need selectable text. Attachments are skipped.</p>
    <button type="submit">
        <span class="import-batch-spinner" aria-hidden="true" hidden></span>
        <span data-import-batch-label>Import batch</span>
    </button>
</form>
<script>
    const importBatchForm = document.querySelector('#import-batch-form');
    const importBatchButton = importBatchForm.querySelector('button[type="submit"]');
    const importBatchSpinner = importBatchButton.querySelector('.import-batch-spinner');
    const importBatchLabel = importBatchButton.querySelector('[data-import-batch-label]');

    function setImportBatchUploading(uploading) {
        importBatchButton.disabled = uploading;
        importBatchSpinner.hidden = !uploading;
        importBatchLabel.textContent = uploading ? 'Uploading…' : 'Import batch';
    }

    importBatchForm.addEventListener('submit', event => {
        if (importBatchButton.disabled) {
            event.preventDefault();
            return;
        }

        setImportBatchUploading(true);
    });
    window.addEventListener('pageshow', () => setImportBatchUploading(false));
</script>
<p><a href="{{ route('imports.queue') }}">View import queue</a></p>
@foreach ($imports as $import)
    <article><a href="{{ route('imports.show', $import->id) }}">{{ $import->filename }}</a> · @if ($import->file_count > 1){{ $import->file_count }} files in batch @else{{ $import->status }}@endif</article>
@endforeach
{{ $imports->links() }}
@endsection
