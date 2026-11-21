<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE inference_budget (id integer primary key, committed bigint not null default 0);
            INSERT INTO inference_budget (id) VALUES (1);
            CREATE TABLE inference_calls (
                id bigserial primary key, purpose text not null, reserved bigint not null,
                actual bigint, input_tokens bigint, created_at timestamptz not null default now()
            );
            CREATE TABLE inference_work (
                id bigserial primary key,
                memory_id uuid unique references memories on delete cascade,
                passage_id bigint unique references mail_passages on delete cascade,
                stage text not null, call_id bigint references inference_calls,
                embedding vector, configuration jsonb,
                check (num_nonnulls(memory_id, passage_id) = 1)
            );
            INSERT INTO inference_work (memory_id,stage) SELECT id,'embed' FROM memories;
            INSERT INTO inference_work (passage_id,stage) SELECT id,'screen' FROM mail_passages WHERE quote_text IS NULL AND wording ~ '[^[:space:]]';
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE inference_work, inference_calls, inference_budget');
    }
};
