<details class="replay-input">
    <summary>{{ $replayLabel ?? 'Replay input' }}</summary>
    <form method="post" action="{{ route('memories.input') }}" class="stack">
        @csrf
        <input type="hidden" name="replay" value="1">
        @if ($replayFollowup ?? false)
            <input type="hidden" name="followup" value="1">
        @endif
        <label for="replay-wording">Your wording</label>
        <textarea id="replay-wording" name="wording" rows="5" maxlength="10000" required>{{ "\n" }}{{ $replayWording }}</textarea>
        <input type="hidden" name="experience_date" value="{{ $replayDate }}">
        <div class="actions">
            <button name="intent" value="ask">Ask my memory</button>
            <button class="secondary" name="intent" value="save">Remember this</button>
        </div>
    </form>
</details>
