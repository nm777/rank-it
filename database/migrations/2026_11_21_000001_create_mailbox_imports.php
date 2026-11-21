<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each MBOX upload is a single-file batch.
        Schema::create('mailbox_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('filename');
            $table->string('status')->default('Queued');
            $table->unsignedBigInteger('position')->default(0);
            $table->integer('imported')->default(0);
            $table->integer('unreadable')->default(0);
            $table->timestamps();
        });
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('identity', 64);
            $table->unique(['user_id', 'identity']);
            $table->text('sender')->nullable();
            $table->text('sent_date')->nullable();
            $table->text('subject')->nullable();
            $table->char('parent_identity', 64)->nullable();
        });
        Schema::create('mailbox_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('import_id')->constrained('mailbox_imports')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->integer('ordinal');
            $table->unique(['import_id', 'ordinal']);
            $table->json('attachments');
        });
        Schema::create('mail_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->integer('ordinal');
            $table->unique(['message_id', 'ordinal']);
            $table->text('wording');
            $table->text('quote_text')->nullable();
        });
        // Resolve quoted evidence at read time, including parents imported after a reply.
        DB::statement(<<<'SQL'
            CREATE VIEW mail_passage_sources AS
            SELECT p.*, m.user_id, m.sender, m.sent_date, m.parent_identity,
                   (SELECT original.id FROM mail_messages parent
                    JOIN mail_passages original ON original.message_id = parent.id
                    WHERE parent.user_id = m.user_id AND parent.identity = m.parent_identity
                      AND original.quote_text IS NULL AND strpos(original.wording, p.quote_text) > 0
                    ORDER BY original.ordinal LIMIT 1) AS source_id
            FROM mail_passages p JOIN mail_messages m ON m.id = p.message_id
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW mail_passage_sources');
        foreach (['mail_passages', 'mailbox_entries', 'mail_messages', 'mailbox_imports'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
