@isset($documentOriginals[$passageId])
    <a href="{{ route('documents.original', $documentOriginals[$passageId]) }}">Download original</a>
@endisset
