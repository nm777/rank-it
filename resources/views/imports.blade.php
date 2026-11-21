@extends('layout')
@section('title', 'Mailbox imports')
@section('content')
<h1>Mailbox imports</h1>
<form class="stack" method="post" enctype="multipart/form-data" action="{{ route('imports.store') }}">
    @csrf
    <label for="mailbox">Choose an MBOX mailbox (up to 16 MB)</label>
    <input type="file" name="mailbox" id="mailbox" required>
    <p>Importing makes message bodies searchable in your private collection. Attachments are skipped.</p>
    <button>Import mailbox</button>
</form>
@foreach ($imports as $import)
    <article><a href="{{ route('imports.show', $import->id) }}">{{ $import->filename }}</a> · {{ $import->status }}</article>
@endforeach
{{ $imports->links() }}
@endsection
