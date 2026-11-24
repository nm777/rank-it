<form method="post" action="{{ route('conversation.dismiss', $turn) }}">@csrf
<button name="source" value="{{ $key }}">Dismiss suggestion</button></form>
