<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors ARCHITECTURE.md Ch.4: policies(id, class_id, package_name,
        // mode, active, version). Server is authoritative -- a teacher edit
        // increments `version`, and the student device compares against its
        // cached policy_version (Ch.3) to know its local copy is stale.
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->string('package_name');
            $table->string('mode')->default('block');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['class_id', 'package_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
