<?php

namespace App\Jobs;

use App\Inference\Budget;
use App\Inference\Providers;
use App\Inference\Ratings;
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
            $providers->select(DB::table('embedding_index_state')->sharedLock()->first()->profile);
            $work = $query->lockForUpdate()->first();
            if (! $work || $work->call_id) {
                return null;
            }
            $work->previousStage = $work->stage;
            $work->contextOnly = in_array($work->stage, ['embed', 'indexed'], true) && $work->context_changed;
            if ($work->contextOnly) {
                $work->stage = 'screen';
            } else {
                Ratings::process($work, $budget);
            }
            if (! in_array($work->stage, ['screen', 'embed'], true)) {
                return null;
            }
            $estimate = $providers->estimate($work->stage);
            if ($estimate === null) {
                return null;
            }

            $source = $work->memory_id
                ? DB::table('memories')->where('id', $work->memory_id)->firstOrFail(['wording', 'user_id'])
                : DB::table('mail_passage_sources')->where('id', $work->passage_id)->firstOrFail();
            if (! ($work->call_id = $budget->reserve($work->stage, $estimate, $source->user_id))) {
                return null;
            }
            (clone $query)->update(['call_id' => $work->call_id]);
            $work->wording = $source->wording;
            if ($work->stage === 'screen') {
                $parent = DB::table('mail_messages')->where('user_id', $source->user_id)
                    ->where('identity', $source->parent_identity)->value('id');
                $work->context = DB::table('mail_passages')->whereIn('message_id', array_filter([$parent, $source->message_id]))
                    ->where('id', '!=', $source->id)->orderByRaw('case when message_id = ? then 0 else 1 end', [$parent])->orderBy('ordinal')
                    ->selectRaw('left(wording, 1000) as excerpt')->limit(8)->get()->pluck('excerpt')->all();
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
                    'configuration' => $providers->configuration()];
                $cost = $tokens * $providers->rate();
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
            $current = $query->lockForUpdate()->first();
            if (! $current || $current->call_id !== $work->call_id) {
                return;
            }
            if ($work->stage === 'screen') {
                if ($work->contextOnly) {
                    DB::table('ratings')->where('passage_id', $current->passage_id)->delete();
                    $updates['agreement'] = null;
                    $updates['ratings_processed'] = $updates['stage'] === 'skipped';
                    $updates['stage'] = $work->previousStage;
                } elseif ($current->context_changed && ! $work->context_changed) {
                    $updates['stage'] = 'screen';
                } else {
                    DB::table('passage_rechecks')->where('work_id', $work->id)->whereNull('qualified')
                        ->update(['qualified' => $updates['stage'] === 'embed']);
                }
            }
            $rescreen = $work->stage === 'embed' && $current->context_changed;
            $query->update($updates + ['call_id' => null, 'context_changed' => $rescreen]);
            if ($rescreen || ($updates['ratings_processed'] ?? true) === false || in_array($updates['stage'], ['embed', 'screen'])) {
                self::dispatch($this->workId);
            }
        });
    }
}
