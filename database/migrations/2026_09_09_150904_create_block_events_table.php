<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ARCHITECTURE.md Ch.4: block_events(id, session_id, student_id,
        // package_name, at, synced). Pushed by students draining their
        // local outbox; `synced` again is a client-local concern, not a
        // server column.
        Schema::create('block_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('class_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->string('package_name');
            $table->timestamp('at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_events');
    }
};
