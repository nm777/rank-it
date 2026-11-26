<?php

namespace App\Inference;

use Illuminate\Support\Facades\DB;

class RebuildIndex
{
    public function run(string $profile, Providers $provider, Budget $budget): bool
    {
        $provider->select($profile);
        $estimate = $provider->estimate('embed');
        if ($estimate === null || ! DB::selectOne('select pg_try_advisory_lock(601104) as acquired')->acquired) {
            return false;
        }
        try {
            $configuration = $provider->configuration();
            $eligible = DB::table('inference_work as w')->where('w.stage', 'indexed');
            if (DB::table('embedding_index_state')->value('profile') === $profile
                && ! (clone $eligible)->whereRaw('w.configuration is distinct from ?::jsonb', [$configuration])->exists()) {
                return true;
            }

            $ready = DB::table('replacement_embeddings')->whereRaw('configuration = ?::jsonb', [$configuration])
                ->whereNotNull('embedding')->select('work_id');
            foreach ((clone $eligible)->whereNotIn('w.id', $ready)->select('w.id')->lazyById(100, 'w.id', 'id') as $item) {
                $call = DB::transaction(function () use ($item, $budget, $estimate, $configuration) {
                    $work = DB::table('inference_work')->where('id', $item->id)->lockForUpdate()->first();
                    if (! $work) {
                        return null;
                    }
                    $row = DB::table('replacement_embeddings')->where('work_id', $work->id);
                    if ($row->value('call_id') || ! ($id = $budget->reserve('rebuild', $estimate))) {
                        return null;
                    }
                    $row->updateOrInsert(['work_id' => $work->id], ['configuration' => $configuration, 'embedding' => null, 'call_id' => $id]);
                    $text = $work->memory_id ? DB::table('memories')->where('id', $work->memory_id)->value('wording')
                        : DB::table('mail_passages')->where('id', $work->passage_id)->value('wording');

                    return [$id, $text];
                });
                if (! $call) {
                    return false;
                }
                [$id, $text] = $call;
                [$vector, $tokens] = $provider->embed($text);
                DB::transaction(function () use ($id, $vector, $budget, $provider, $tokens) {
                    $budget->settle($id, $tokens * $provider->rate(), $tokens);
                    DB::table('replacement_embeddings')->where('call_id', $id)
                        ->update(['call_id' => null, 'embedding' => json_encode($vector, JSON_THROW_ON_ERROR)]);
                });
            }

            return DB::transaction(function () use ($profile, $configuration, $eligible, $ready) {
                DB::table('embedding_index_state')->lockForUpdate()->first();
                if (DB::table('inference_work')->where('stage', 'embed')->whereNotNull('call_id')->exists()
                    || (clone $eligible)->whereNotIn('w.id', $ready)->exists()) {
                    return false;
                }
                DB::statement("update inference_work w set embedding = r.embedding, configuration = r.configuration
                    from replacement_embeddings r where w.id = r.work_id and w.stage = 'indexed'
                    and r.configuration = ?::jsonb and r.embedding is not null", [$configuration]);
                DB::table('embedding_index_state')->update(['profile' => $profile]);
                DB::table('replacement_embeddings')->delete();

                return true;
            });
        } finally {
            DB::select('select pg_advisory_unlock(601104)');
        }
    }
}
