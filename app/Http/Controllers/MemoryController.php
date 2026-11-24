<?php

namespace App\Http\Controllers;

use App\Inference\Conversation;
use App\Inference\Ratings;
use App\Inference\RatingSearch;
use App\Inference\Recall;
use App\Jobs\IndexPassage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemoryController extends Controller
{
    public function index(Request $request)
    {
        return view('memories', [
            'results' => $request->user()->memories()->latest()->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function input(Request $request, Recall $recall, Conversation $conversation)
    {
        $data = $this->validateMemory($request);
        $choice = $request->validate(['intent' => ['nullable', 'in:ask,save']])['intent'] ?? null;
        $text = trim($data['wording']);
        if ($request->boolean('followup') && preg_match('/^(?:please )?(?:remember (?:this|that)|save (?:this|that)(?: fact)?)[:,]?\s+(.+)$/isu', $text, $explicit)) {
            $request->merge(['wording' => trim($explicit[1])]);

            return $this->store($request);
        }
        if ($choice === 'save') {
            return $this->store($request);
        }
        if ($choice === 'ask' || str_contains($text, '?')
            || preg_match('/^(?:who|what|when|where|why|how|which|did|does|can|could|would|should|suppose|imagine|if)\b/iu', $text)) {
            $followup = $request->boolean('followup');
            if (! $followup) {
                $conversation->reset();
            }
            [$context, $previous] = $followup ? $conversation->context() : [[], []];
            $dismissed = array_keys($conversation->current()['dismissed']);
            $search = implode("\n", [...$context, $text]);
            if ($ratings = (new RatingSearch)->ask($request->user()->id, $search)) {
                return $this->ratingAnswer($ratings, $text, $conversation);
            }
            $response = $recall->ask($request->user()->id, $text, $context, $dismissed, $previous);
            $answer = $response['answer'];
            $keys = count($answer['clarification'] ?? []) > 1
                ? $answer['clarification'] : array_column($answer['claims'] ?? [], 'key');
            $keys = array_unique([...$keys, ...array_column($answer['uncertain'] ?? [], 'key')]);
            if (! $keys) {
                $keys = array_keys($response['results']);
            }
            $versions = [];
            foreach ($keys as $key) {
                $versions[$key] = $response['results'][$key]->version;
            }
            $turn = $conversation->remember($text, $versions);

            return view('recall', $response + compact('turn'));
        }
        if (preg_match('/^(?:remember (?:this|that)\b|I (?:parked|left|put|stored|bought|hired|liked|loved|preferred)\b)/iu', $text)
            && ! preg_match('/\b(?:if|maybe|suppose|hypothetically|might|would)\b/iu', $text)) {
            return $this->store($request);
        }
        if (! preg_match('/\b(?:if|maybe|might|would|not|hypothetically)\b/iu', $text) && Ratings::extract($text, null)) {
            return $this->store($request);
        }

        return view('clarify', $data);
    }

    public function search(Request $request)
    {
        $data = $request->validate(['wording' => ['required', 'string', 'max:10000', 'not_regex:/\x00/']]);
        $results = $request->user()->memories()
            ->whereRaw('strpos(lower(wording), lower(?)) > 0', [trim($data['wording'])])
            ->latest()->orderByDesc('id')->limit(50)->get();

        $passages = DB::table('mail_passage_sources')->where('user_id', $request->user()->id)
            ->whereNull('source_id')
            ->whereRaw('strpos(lower(coalesce(quote_text, wording)), lower(?)) > 0', [trim($data['wording'])])
            ->selectRaw("distinct on (case when quote_text is not null and parent_identity is not null then parent_identity || ':' || quote_text else id::text end) *")
            ->limit(50)->get();

        return view('memories', ['results' => $results, 'passages' => $passages, 'query' => $data['wording']]);
    }

    public function store(Request $request)
    {
        $data = $this->validateMemory($request);
        $memory = DB::transaction(function () use ($request, $data) {
            $memory = $request->user()->memories()->make($data);
            if ($request->route('passage') !== null) {
                $source = DB::table('mail_passages')->join('mail_messages', 'mail_messages.id', '=', 'mail_passages.message_id')
                    ->where('mail_messages.user_id', $request->user()->id)->where('mail_passages.id', $request->route('passage'))
                    ->lockForUpdate()->firstOrFail(['mail_passages.id']);
                $memory->corrects_passage_id = $source->id;
            }
            $memory->save();
            Ratings::capture($memory);
            IndexPassage::enqueue(['memory_id' => $memory->id], 'embed');

            return $memory;
        });

        return redirect()->route('memories.show', $memory)->with('status', 'Saved')->with('evidence_changed', true);
    }

    public function show(Request $request, string $memory)
    {
        return view('memory', [
            'ratings' => DB::table('ratings')->where('memory_id', $memory)->get(),
            'memory' => $request->user()->memories()->findOrFail($memory),
        ]);
    }

    public function update(Request $request, string $memory)
    {
        $data = $this->validateMemory($request);
        DB::transaction(function () use ($request, $memory, $data) {
            $entry = $request->user()->memories()->lockForUpdate()->findOrFail($memory);
            $entry->update($data);
            Ratings::capture($entry);
            if ($entry->wasChanged('wording')) {
                DB::table('inference_work')->where('memory_id', $memory)->delete();
                IndexPassage::enqueue(['memory_id' => $memory], 'embed');
            }
        });

        return redirect()->route('memories.show', $memory)->with('status', 'Saved')->with('evidence_changed', true);
    }

    public function identify(Request $request, int $message)
    {
        $data = $request->validate(['scope' => 'required|in:message,address,other', 'wording' => 'required|string|max:10000']);
        $source = DB::table('mail_messages')->where('user_id', $request->user()->id)->where('id', $message);
        $address = RatingSearch::address($source->firstOrFail()->sender);
        if ($data['scope'] === 'address') {
            abort_unless($address, 422);
            DB::table('personal_addresses')->insertOrIgnore(['user_id' => $request->user()->id, 'address' => $address]);
        } else {
            $source->update(['authored_by_me' => $data['scope'] === 'message']);
        }

        return $this->ratingAnswer((new RatingSearch)->ask($request->user()->id, $data['wording']) ?? abort(422), $data['wording'], new Conversation($request));
    }

    public function identifyPerson(Request $request, string $memory)
    {
        $person = $request->validate(['person' => 'required|string|max:255'])['person'];
        $entry = $request->user()->memories()->findOrFail($memory);
        $subjects = DB::table('ratings')->where('memory_id', $entry->id)->distinct()->pluck('subject');
        $subject = $request->validate(['subject' => 'nullable|string|max:10000'])['subject'] ?? ($subjects->count() === 1 ? $subjects->first() : null);
        abort_if($subjects->isNotEmpty() && ! $subjects->containsStrict($subject), 422);
        $entry->identified_subject = $subject;
        $entry->identified_person = $person;
        $entry->save();

        return redirect()->route('memories.show', $memory);
    }

    private function ratingAnswer(array $ratings, string $question, Conversation $conversation)
    {
        $versions = [];
        $dismissed = $conversation->current()['dismissed'];
        foreach (['confirmed', 'uncertain', 'identities'] as $group) {
            foreach ($ratings[$group] as $index => $row) {
                $key = $row->memory_id ? 'memory:'.$row->memory_id : 'passage:'.$row->passage_id;
                if (isset($dismissed[$key])) {
                    unset($ratings[$group][$index]);

                    continue;
                }
                $versions[$key] = $row->source_version;
                if ($row->reply) {
                    $versions['passage:'.$row->reply] = $row->reply_version;
                }
            }
        }
        $turn = $conversation->remember($question, $versions);
        $ratings['question'] = $question;

        return view('rating-results', $ratings + compact('turn'));
    }

    public function status(Conversation $conversation, string $turn)
    {
        $evidence = $conversation->turn($turn)['evidence'];
        return response()->json($conversation->changes($evidence));
    }

    public function dismiss(Request $request, Conversation $conversation, string $turn)
    {
        $conversation->dismiss($turn, $request->validate(['source' => 'required|string'])['source']);

        return view('followup', ['question' => $conversation->turn($turn)['question']]);
    }

    public function reset(Conversation $conversation)
    {
        $conversation->reset();

        return redirect()->route('memories.index');
    }

    public function delete(Request $request, string $memory)
    {
        $request->user()->memories()->findOrFail($memory)->delete();

        return redirect()->route('memories.index')->with('evidence_changed', true);
    }

    private function validateMemory(Request $request): array
    {
        return $request->validate([
            'wording' => ['required', 'string', 'max:10000', 'not_regex:/\x00/'],
            'experience_date' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! preg_match('/\A([0-9]{4})(?:-([0-9]{2})(?:-([0-9]{2}))?)?\z/', $value, $parts)
                    || ! checkdate((int) ($parts[2] ?? 1), (int) ($parts[3] ?? 1), (int) $parts[1])) {
                    $fail('Enter a year, month, or day in YYYY, YYYY-MM, or YYYY-MM-DD format.');
                }
            }],
        ]) + ['experience_date' => null];
    }
}
