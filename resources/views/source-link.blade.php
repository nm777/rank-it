@php
    [$type, $id] = explode(':', $source->key, 2);
    $params = $type === 'memory' ? ['memory' => $id, 'highlight' => 1] : ['passage' => $id];
    $anchor = $type === 'memory' ? 'passage' : 'passage-'.$id;
    if (isset($excerpt)) {
        $params['start'] = mb_strpos($source->wording, $excerpt);
        $params['length'] = mb_strlen($excerpt);
        $anchor = 'evidence';
    }
    $url = route($type === 'memory' ? 'memories.show' : 'passages.show', $params).'#'.$anchor;
@endphp
<a href="{{ $url }}">Open passage</a>
<small>{{ $source->author ?: 'Unknown author' }} · {{ $source->date ?: 'Unknown date' }}</small>
