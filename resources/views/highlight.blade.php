@php
    $start = request()->integer('start', -1);
    $length = request()->integer('length');
    $hasExcerpt = $start >= 0 && $length > 0 && $start <= mb_strlen($wording) - $length;
@endphp
@if ($hasExcerpt){{ mb_substr($wording, 0, $start) }}<mark id="evidence">{{ mb_substr($wording, $start, $length) }}</mark>{{ mb_substr($wording, $start + $length) }}@elseif ($highlightWholePassage ?? true)<mark>{{ $wording }}</mark>@else{{ $wording }}@endif
