<?php

namespace App\Jobs;

use App\Inference\Budget;
use App\Inference\ProviderResponseException;
use App\Inference\Providers;
use App\Inference\Ratings;
use App\Inference\WorkTransitions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IndexPassage implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $workId, public ?int $retryCallId = null)
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
        $retryCallId = $this->retryCallId ?? null;
        $work = DB::transaction(function () use ($query, $providers, $budget, $retryCallId) {
            $providers->select(DB::table('embedding_index_state')->sharedLock()->first()->profile);
            $work = $query->lockForUpdate()->first();
            if (! $work || ($work->call_id === null ? null : (int) $work->call_id) !== $retryCallId) {
                return null;
            }
            $work->previousStage = $work->stage;
            $work->contextOnly = in_array($work->stage, ['embed', 'indexed'], true) && $work->context_changed;
            if ($work->contextOnly) {
                $work->stage = 'screen';
            } else {
                Ratings::process($work);
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
            [$result, $tokens] = $work->stage === 'screen'
                ? $providers->screen($work->wording, $work->context)
                : $providers->embed($work->wording);
        } catch (ConnectionException|RequestException|ProviderResponseException $error) {
            // TypeSafe documents rejected requests as unbilled. Other outcomes may have been charged.
            if ($work->stage === 'embed' && $error instanceof ConnectionException && $retryCallId === null) {
                // Keep the original reservation until its actual cost can be reconciled.
                self::dispatch($this->workId, (int) $work->call_id)->delay(now()->addSeconds(30));
                Log::info('Retrying passage embedding after a connection interruption.', [
                    'work_id' => $work->id,
                    'call_id' => $work->call_id,
                ]);
            } elseif ($work->stage === 'screen' && $error instanceof RequestException && $error->response->clientError()) {
                DB::transaction(function () use ($budget, $query, $work) {
                    $budget->settle($work->call_id, 0, 0);
                    $query->where('call_id', $work->call_id)->update(['call_id' => null]);
                });
            } else {
                Log::warning('Passage indexing is blocked pending inference call reconciliation.', [
                    'work_id' => $work->id,
                    'call_id' => $work->call_id,
                    'stage' => $work->stage,
                    'error_type' => $error::class,
                    'http_status' => $error instanceof RequestException ? $error->response->status() : null,
                ]);
            }

            return;
        }

        if ($work->stage === 'screen') {
            $updates = ['stage' => $result ? 'embed' : 'skipped'];
            $cost = $tokens * Providers::JEV_RATE;
        } else {
            $updates = ['stage' => 'indexed', 'embedding' => json_encode($result, JSON_THROW_ON_ERROR),
                'configuration' => $providers->configuration()];
            $cost = $tokens * $providers->rate();
        }

        DB::transaction(function () use ($updates, $work, $cost, $tokens, $budget) {
            $budget->settle($work->call_id, $cost, $tokens);
            WorkTransitions::complete($work, $updates);
        });
    }
}
