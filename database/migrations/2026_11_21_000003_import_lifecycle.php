<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE mailbox_imports ADD COLUMN batch_id uuid, ADD COLUMN excluded integer NOT NULL DEFAULT 0;
            UPDATE mailbox_imports SET batch_id = id;
            ALTER TABLE mailbox_imports ALTER COLUMN batch_id SET NOT NULL;
            CREATE INDEX mailbox_import_batch ON mailbox_imports(user_id, batch_id);
            CREATE TABLE import_exclusions (
                import_id uuid REFERENCES mailbox_imports ON DELETE CASCADE,
                identity char(64), PRIMARY KEY(import_id, identity)
            );
            ALTER TABLE inference_work ADD COLUMN context_changed boolean NOT NULL DEFAULT false;
            CREATE TABLE passage_rechecks (
                batch_id uuid NOT NULL, work_id bigint REFERENCES inference_work ON DELETE CASCADE,
                qualified boolean, PRIMARY KEY(batch_id, work_id)
            );
            CREATE TABLE embedding_index_state (id integer PRIMARY KEY, profile text NOT NULL);
            INSERT INTO embedding_index_state VALUES (1, 'default');
            CREATE TABLE replacement_embeddings (
                work_id bigint PRIMARY KEY REFERENCES inference_work ON DELETE CASCADE,
                configuration jsonb NOT NULL, embedding vector, call_id bigint REFERENCES inference_calls
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE replacement_embeddings, embedding_index_state, passage_rechecks, import_exclusions;
            ALTER TABLE inference_work DROP COLUMN context_changed; ALTER TABLE mailbox_imports DROP COLUMN batch_id, DROP COLUMN excluded');
    }
};
