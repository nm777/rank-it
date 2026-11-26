<?php

namespace App\Inference;

use App\Jobs\IndexPassage;
use Illuminate\Support\Facades\DB;

/** Apply transitions inside the caller's transaction, with the work row locked before changing it. */
class WorkTransitions
{
    public static function contextArrived(object $work): void
    {
        if ($work->stage === 'screen' && ! $work->call_id) {
            return;
        }

        self::apply($work->id, $work->stage === 'skipped' ? ['stage' => 'screen'] : ['context_changed' => true]);
    }

    public static function promote(int $workId): void
    {
        self::apply($workId, ['stage' => 'embed']);
    }

    public static function recheck(int $workId, string $batch): void
    {
        DB::table('passage_rechecks')->updateOrInsert(['batch_id' => $batch, 'work_id' => $workId], ['qualified' => null]);
        self::apply($workId, ['stage' => 'screen']);
    }

    /** @param array{stage: string, embedding?: string, configuration?: string} $updates */
    public static function complete(object $work, array $updates): void
    {
        $current = DB::table('inference_work')->where('id', $work->id)->lockForUpdate()->first();
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
        $continue = $rescreen || ($updates['ratings_processed'] ?? true) === false || in_array($updates['stage'], ['embed', 'screen'], true);
        self::apply($work->id, $updates + ['call_id' => null, 'context_changed' => $rescreen], $continue);
    }

    private static function apply(int $workId, array $updates, bool $continue = true): void
    {
        DB::table('inference_work')->where('id', $workId)->update($updates);
        if ($continue) {
            IndexPassage::dispatch($workId);
        }
    }
}
