<?php

use App\Inference\Ratings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TABLE ratings (
            id bigserial primary key, memory_id uuid references memories on delete cascade,
            passage_id bigint references mail_passages on delete cascade,
            subject text not null, aspect text not null, score numeric not null, scale numeric, experience text,
            check(num_nonnulls(memory_id,passage_id)=1));
            CREATE INDEX ratings_memory ON ratings(memory_id);
            CREATE INDEX ratings_passage ON ratings(passage_id);
            ALTER TABLE inference_work ADD COLUMN ratings_processed boolean not null default false, ADD COLUMN agreement text;
            CREATE TABLE personal_addresses(user_id bigint references users on delete cascade, address text, primary key(user_id,address));
            ALTER TABLE mail_messages ADD COLUMN authored_by_me boolean;
            ALTER TABLE memories ADD COLUMN identified_person text, ADD COLUMN identified_subject text;');
        DB::table('memories')->eachById(fn ($memory) => Ratings::capture($memory));
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE ratings, personal_addresses;
            ALTER TABLE inference_work DROP COLUMN ratings_processed, DROP COLUMN agreement;
            ALTER TABLE mail_messages DROP COLUMN authored_by_me; ALTER TABLE memories DROP COLUMN identified_person, DROP COLUMN identified_subject');
    }
};
