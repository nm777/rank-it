<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
        });

        Schema::create('memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('wording');
            $table->string('experience_date', 10)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
        Schema::dropIfExists('invitations');
    }
};
