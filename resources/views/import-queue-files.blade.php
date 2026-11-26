@forelse ($files as $file)
    <article>
        <a href="{{ route('imports.show', $file->id) }}">{{ $file->filename }}</a> · {{ $file->status }}
        @if ($file->imported || $file->unreadable || $file->excluded)
            <div class="import-file-counts">
                {{ $file->imported }} imported
                @if ($file->unreadable) · {{ $file->unreadable }} unreadable @endif
                @if ($file->excluded) · {{ $file->excluded }} skipped @endif
            </div>
        @endif
    </article>
@empty
    <p>No imports in the queue.</p>
@endforelse
