<?php

namespace App\Inference;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Conversation
{
    public function __construct(private Request $request) {}

    private function key(): string
    {
        return 'recall.'.$this->request->user()->id;
    }

    public function current(): array
    {
        return $this->request->session()->get($this->key(), ['turns' => [], 'dismissed' => []]);
    }

    public function reset(): void
    {
        $this->request->session()->forget($this->key());
    }

    public function remember(string $question, array $evidence): string
    {
        $conversation = $this->current();
        $token = (string) Str::uuid();
        $conversation['turns'][$token] = compact('question', 'evidence');
        $this->request->session()->put($this->key(), $conversation);

        return $token;
    }

    public function turn(string $token): array
    {
        return $this->current()['turns'][$token] ?? abort(404);
    }

    public function dismiss(string $token, string $key): void
    {
        $turn = $this->turn($token);
        abort_unless(array_key_exists($key, $turn['evidence']), 422);
        $conversation = $this->current();
        $conversation['dismissed'][$key] = true;
        $this->request->session()->put($this->key(), $conversation);
    }

    public function versions(array $keys): array
    {
        $versions = [];
        foreach (['memory' => 'memories', 'passage' => 'mail_passage_sources'] as $kind => $table) {
            $ids = [];
            foreach ($keys as $key) {
                [$prefix, $id] = explode(':', $key, 2);
                if ($prefix === $kind) {
                    $ids[] = $id;
                }
            }
            if (! $ids) {
                continue;
            }
            $fingerprint = self::fingerprint($kind);
            foreach (DB::table($table)->where('user_id', $this->request->user()->id)->whereIn('id', $ids)
                ->selectRaw("id, $fingerprint as version")->get() as $row) {
                $versions[$kind.':'.$row->id] = $row->version;
            }
        }

        return $versions;
    }

    public static function fingerprint(string $kind, string $prefix = ''): string
    {
        $fields = $kind === 'memory'
            ? ['wording', 'experience_date', 'identified_person', 'identified_subject', 'corrects_passage_id']
            : ['wording', 'sender', 'sent_date'];
        $columns = implode(', ', array_map(fn ($field) => $prefix.$field, $fields));

        return "md5(jsonb_build_array($columns)::text)";
    }

    public function changes(array $evidence): array
    {
        $current = $this->versions(array_keys($evidence));
        $changed = array_keys(array_diff_assoc($evidence, $current));
        $status = array_diff_key($evidence, $current) ? 'deleted' : ($changed ? 'edited' : 'current');

        return compact('status', 'changed');
    }

    public function context(): array
    {
        $questions = $previous = [];
        foreach (array_slice($this->current()['turns'], -6) as $turn) {
            if ($this->changes($turn['evidence'])['status'] !== 'deleted') {
                $previous = array_keys($turn['evidence']);
                $questions[] = mb_substr($turn['question'], 0, 1200);
            }
        }

        return [$questions, array_diff($previous, array_keys($this->current()['dismissed']))];
    }
}
