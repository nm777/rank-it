<form method="post" action="{{ route('conversation.dismiss', $turn) }}">@csrf
<button class="text-action" name="source" value="{{ $key }}">Dismiss suggestion</button></form>
