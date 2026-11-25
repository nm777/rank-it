<?php

namespace App\Inference;

use Illuminate\Support\Facades\DB;

class Budget
{
    public function canAffordAnswer(int $amount): bool
    {
        $used = DB::table('inference_budget')->where('id', 1)->value('committed');

        return $amount <= (int) config('inference.cap') - $used;
    }

    public function reserve(string $purpose, int $amount): ?int
    {
        throw_if($amount < 0, \InvalidArgumentException::class);

        return DB::transaction(function () use ($purpose, $amount) {
            $budget = DB::table('inference_budget')->where('id', 1);
            $used = $budget->lockForUpdate()->first()->committed;
            $limit = (int) config('inference.cap');
            if (! in_array($purpose, ['answer', 'intent'], true)) {
                $limit -= max(0, (int) config('inference.reserve'));
            }
            if ($amount > $limit - $used) {
                return null;
            }
            $budget->increment('committed', $amount);

            return DB::table('inference_calls')->insertGetId(['purpose' => $purpose, 'reserved' => $amount]);
        });
    }

    public function settle(int $id, int $actual, ?int $tokens): void
    {
        throw_if($actual < 0, \InvalidArgumentException::class);
        DB::transaction(function () use ($id, $actual, $tokens) {
            $query = DB::table('inference_calls')->where('id', $id);
            $call = $query->lockForUpdate()->firstOrFail();
            if ($call->actual === null) {
                DB::table('inference_budget')->where('id', 1)->increment('committed', $actual - $call->reserved);
                $query->update(['actual' => $actual, 'input_tokens' => $tokens]);
            }
        });
    }
}
