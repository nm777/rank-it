@php($originalId = $originalMessageId ?? $documentOriginals[$passageId] ?? null)
@isset($originalId)
    <a data-document-original download target="_blank" rel="noopener" href="{{ route('documents.original', $originalId) }}">Download original</a>
@endisset
