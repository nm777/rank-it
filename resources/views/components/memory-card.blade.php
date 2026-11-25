@props(['memory' => null])

<article {{ $attributes }}>
    @if ($memory !== null)
        <p class="answer-wording">
            {{ \Illuminate\Support\Str::limit($memory->wording, 120) }}
        </p>
        <footer class="memory-card-footer">
            <small>Saved {{ $memory->created_at->toDateString() }}</small>
            <a class="memory-card-edit" href="{{ route('memories.show', ['memory' => $memory, 'edit' => 1]) }}#correct-memory" aria-label="Edit memory">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m14 6 4 4M4 20l5-1L21 7l-4-4L5 15Z"/>
                </svg>
            </a>
        </footer>
    @elseif (isset($actions))
        <p class="answer-wording">{{ $slot }}</p>
        <div class="evidence-actions">{{ $actions }}</div>
    @else
        {{ $slot }}
    @endif
</article>
