<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at', 'id']);
            $table->index(['user_id', 'completed_at']);
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_title_not_blank CHECK (title ~ '[^[:space:]]')");
        DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_notes_length CHECK (char_length(notes) <= 5000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
