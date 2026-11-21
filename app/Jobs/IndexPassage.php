<?php

namespace App\Jobs;

use App\Inference\Budget;
use App\Inference\Providers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;

class IndexPassage implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workId)
    {
        $this->onConnection('database');
    }

    public static function enqueue(array $source, string $stage): void
    {
        self::dispatch(DB::table('inference_work')->insertGetId($source + ['stage' => $stage]));
    }

    public function handle(Providers $providers, Budget $budget): void
    {
        $query = DB::table('inference_work')->where('id', $this->workId);
        $work = DB::transaction(function () use ($query, $providers, $budget) {
            $work = $query->lockForUpdate()->first();
            if (! $work || $work->call_id || ! in_array($work->stage, ['screen', 'embed'], true)) {
                return null;
            }
            $estimate = $providers->estimate($work->stage);
            if ($estimate === null || ! ($work->call_id = $budget->reserve($work->stage, $estimate))) {
                return null;
            }
            $query->update(['call_id' => $work->call_id]);
            if ($work->memory_id) {
                $work->wording = DB::table('memories')->where('id', $work->memory_id)->value('wording');
            } else {
                $passage = DB::table('mail_passage_sources')->where('id', $work->passage_id)->firstOrFail();
                $work->wording = $passage->wording;
                if ($work->stage === 'screen') {
                    $parent = DB::table('mail_messages')->where('user_id', $passage->user_id)
                        ->where('identity', $passage->parent_identity)->value('id');
                    $work->context = DB::table('mail_passages')->whereIn('message_id', array_filter([$parent, $passage->message_id]))
                        ->where('id', '!=', $passage->id)->orderBy('message_id')->orderBy('ordinal')
                        ->selectRaw('left(wording, 1000) as excerpt')->limit(8)->get()->pluck('excerpt')->all();
                }
            }

            return $work;
        });
        if (! $work) {
            return;
        }

        try {
            if ($work->stage === 'screen') {
                [$keep, $tokens] = $providers->screen($work->wording, $work->context);
                $updates = ['stage' => $keep ? 'embed' : 'skipped'];
                $cost = $tokens * Providers::JEV_RATE;
            } else {
                [$vector, $tokens] = $providers->embed($work->wording);
                $updates = ['stage' => 'indexed', 'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
                    'configuration' => json_encode(['provider' => config('inference.provider'),
                        'model' => config('inference.model'), 'dimensions' => count($vector)], JSON_THROW_ON_ERROR)];
                $cost = $tokens * (int) config('inference.rate');
            }
        } catch (\Throwable $error) {
            // TypeSafe documents rejected requests as unbilled. Other outcomes may have been charged.
            if ($work->stage === 'screen' && $error instanceof RequestException && $error->response->clientError()) {
                DB::transaction(function () use ($budget, $query, $work) {
                    $budget->settle($work->call_id, 0, 0);
                    $query->where('call_id', $work->call_id)->update(['call_id' => null]);
                });
            }

            return;
        }

        DB::transaction(function () use ($query, $updates, $work, $cost, $tokens, $budget) {
            $budget->settle($work->call_id, $cost, $tokens);
            if ($query->where('call_id', $work->call_id)->update($updates + ['call_id' => null]) && $updates['stage'] === 'embed') {
                self::dispatch($this->workId);
            }
        });
    }
}
