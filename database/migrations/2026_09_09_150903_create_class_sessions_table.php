<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named `class_sessions`, not `sessions` -- Laravel's own
        // SESSION_DRIVER=database already owns a `sessions` table for web
        // session storage. This is the domain concept from ARCHITECTURE.md
        // Ch.4: sessions(id, class_id, started_at, ended_at, active, synced).
        // `synced` is meaningful on the CLIENT'S local copy (has this row
        // been pushed from the outbox yet); server rows are always the
        // synced truth, so no `synced` column here.
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
