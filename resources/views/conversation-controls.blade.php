@if (isset($turn))
<p id="evidence-status" role="status"></p>
<form id="ask-again" hidden method="post" action="{{ route('memories.input') }}">@csrf
<input type="hidden" name="intent" value="ask"><input type="hidden" name="followup" value="1">
<input type="hidden" name="wording" value="{{ $question }}"><button>Ask again</button></form>
<script>
(() => {
    const answer = document.querySelector('#answer');
    let sequence = 0;
    async function check() {
        const current = ++sequence;
        try {
            const response = await fetch(@js(route('conversation.status', $turn)), {cache: 'no-store', headers: {Accept: 'application/json'}});
            const {status, changed} = response.ok ? await response.json() : {status: 'expired'};
            if (sequence === current && status === 'edited') {
                for (const link of answer.querySelectorAll('a[data-source]')) {
                    if (!changed.includes(link.dataset.source)) continue;
                    const url = new URL(link.href);
                    url.searchParams.delete('start');
                    url.searchParams.delete('length');
                    url.hash = 'passage';
                    link.href = url;
                }
            }
            if (sequence !== current || status === 'current') return;
            if (status !== 'edited') answer.replaceChildren();
            document.querySelector('#evidence-status').textContent = {edited: 'Supporting memory edited', deleted: 'A supporting memory was deleted', expired: 'Conversation ended'}[status];
            document.querySelector('#ask-again').hidden = status === 'expired';
        } catch {}
    }
    window.addEventListener('pageshow', check);
    window.addEventListener('focus', check);
    if (window.BroadcastChannel) new BroadcastChannel('rank-it-evidence').onmessage = check;
    setInterval(() => { if (!document.hidden) check(); }, 5000);
})();
</script>
@endif
<form method="post" action="{{ route('memories.input') }}" class="stack">@csrf
<input type="hidden" name="followup" value="1">
<label>Follow up <textarea name="wording" required maxlength="10000"></textarea></label>
<button name="intent" value="ask">Ask my memory</button><button name="intent" value="save">Save as a memory</button></form>
<form method="post" action="{{ route('conversation.new') }}">@csrf<button>New conversation</button></form>
