<?php

namespace App\Http\Controllers;

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
            $memory = $request->user()->memories()->create($data);
            IndexPassage::enqueue(['memory_id' => $memory->id], 'embed');

            return $memory;
        });

        return redirect()->route('memories.show', $memory)->with('status', 'Saved');
    }

    public function show(Request $request, string $memory)
    {
        return view('memory', [
            'memory' => $request->user()->memories()->findOrFail($memory),
        ]);
    }

    public function update(Request $request, string $memory)
    {
        $data = $this->validateMemory($request);
        DB::transaction(function () use ($request, $memory, $data) {
            $entry = $request->user()->memories()->lockForUpdate()->findOrFail($memory);
            $entry->update($data);
            if ($entry->wasChanged('wording')) {
                DB::table('inference_work')->where('memory_id', $memory)->delete();
                IndexPassage::enqueue(['memory_id' => $memory], 'embed');
            }
        });

        return redirect()->route('memories.show', $memory)->with('status', 'Saved');
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
