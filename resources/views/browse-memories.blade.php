@extends('layout')
@section('title', 'Memories')
@section('content')
<h1>Memories</h1>
<form method="get" action="{{ route('memories.browse') }}" role="search" class="memory-search">
    <input type="search" name="q" aria-label="Search memories" placeholder="Search memories" value="{{ $search }}" maxlength="10000">
    <button type="submit">Search</button>
</form>
<section id="memory-results" class="answer-results" aria-label="Memory results">
    @forelse ($memories as $memory)
        <x-memory-card :memory="$memory" />
    @empty
        <p>{{ $search === '' ? 'Your saved memories will appear here.' : 'No matching memories.' }}</p>
    @endforelse
</section>
<div class="memory-pagination">
    @if ($memories->previousPageUrl())
        <a href="{{ $memories->previousPageUrl() }}">Previous page</a>
    @endif
    @if ($memories->nextPageUrl())
        <a id="more-memories" href="{{ $memories->nextPageUrl() }}">Load more memories</a>
    @endif
</div>
<p id="memory-load-status" role="status"></p>
<script>
    const moreMemories = document.querySelector('#more-memories');

    if (moreMemories && 'IntersectionObserver' in window) {
        const results = document.querySelector('#memory-results');
        const status = document.querySelector('#memory-load-status');
        const observer = new IntersectionObserver(entries => {
            if (entries.some(entry => entry.isIntersecting)) loadMemories();
        });
        let loading = false;

        async function loadMemories() {
            if (loading) return;
            loading = true;
            observer.unobserve(moreMemories);
            status.textContent = 'Loading memories…';

            try {
                const response = await fetch(moreMemories.href);
                if (!response.ok) throw new Error();
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const nextResults = page.querySelector('#memory-results');
                if (!nextResults) throw new Error();

                results.append(...nextResults.querySelectorAll('article'));
                const nextLink = page.querySelector('#more-memories');
                if (nextLink) {
                    moreMemories.href = nextLink.href;
                    status.textContent = '';
                    observer.observe(moreMemories);
                } else {
                    status.textContent = 'All memories loaded.';
                    if (document.activeElement === moreMemories) {
                        status.tabIndex = -1;
                        status.focus();
                    }
                    moreMemories.remove();
                }
            } catch {
                status.textContent = 'Could not load more memories. Use the link to try again.';
            } finally {
                loading = false;
            }
        }

        observer.observe(moreMemories);
    }
</script>
@endsection
