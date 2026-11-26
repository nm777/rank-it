<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('memory_id')->constrained()->cascadeOnDelete();
            $table->string('mime_type');
            $table->binary('contents');
            $table->index(['memory_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_attachments');
    }
};
