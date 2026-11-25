<div class="question-navigation">
    <a href="{{ route('memories.index') }}">‹ My memories</a>
    <form method="post" action="{{ route('conversation.new') }}">
        @csrf
        <button class="text-action">New conversation</button>
    </form>
</div>
<div class="question-heading">
    <h1>{{ $question }}</h1>
    @include('replay-input', [
        'replayWording' => $replayWording ?? $question,
        'replayDate' => $experienceDate ?? null,
        'replayFollowup' => request()->boolean('followup'),
        'replayLabel' => 'Edit & replay',
    ])
</div>
