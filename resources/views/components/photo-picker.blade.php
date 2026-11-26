<div class="photo-picker stack" data-photo-picker>
    <div class="actions">
        <input name="{{ ($multiple ?? false) ? 'photos[]' : 'photo' }}" type="file" @if ($multiple ?? false) multiple @endif aria-label="Add photo" accept="image/jpeg,image/png,image/webp" @required($required ?? false)>
        <button type="button" class="secondary" data-photo-open hidden>Add photo</button>
        {{ $slot }}
    </div>
    <div class="selected-photo" data-selected-photo hidden>
        <ul aria-live="polite" data-photo-names></ul>
        <button type="button" class="text-action" data-photo-clear>Clear selection</button>
    </div>
</div>
<script src="{{ asset('memory-photo.js') }}" defer></script>
