<article>
    <p class="answer-wording">
        <a href="{{ route('memories.show', $memory) }}">{{ \Illuminate\Support\Str::limit($memory->wording, 120) }}</a>
    </p>
    <small>Saved {{ $memory->created_at->toDateString() }}</small>
</article>
