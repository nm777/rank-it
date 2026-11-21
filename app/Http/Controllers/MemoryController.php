<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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

        return view('memories', ['results' => $results, 'query' => $data['wording']]);
    }

    public function store(Request $request)
    {
        $memory = $request->user()->memories()->create($this->validateMemory($request));

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
        $memory = $request->user()->memories()->findOrFail($memory);
        $memory->update($this->validateMemory($request));

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
