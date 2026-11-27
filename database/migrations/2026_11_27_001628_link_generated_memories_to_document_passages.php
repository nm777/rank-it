<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            $table->foreignId('document_passage_id')->nullable()->unique()->constrained('mail_passages')->cascadeOnDelete();
        });
        Schema::table('mail_passages', function (Blueprint $table): void {
            $table->boolean('memory_generation_checked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_passage_id');
        });
        Schema::table('mail_passages', function (Blueprint $table): void {
            $table->dropColumn('memory_generation_checked');
        });
    }
};
