<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('diagnostic_runs')->cascadeOnDelete();
            $table->string('check_class', 191);
            // Snapshotted so a run still renders after a check is renamed or removed.
            $table->string('title', 191);
            $table->string('category', 64);
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('batch')->nullable();
            $table->boolean('parallel_safe')->default(true);
            $table->boolean('can_fail')->default(true);
            $table->string('status', 16)->default('pending');
            $table->string('outcome', 16)->nullable();
            $table->boolean('downgraded')->default(false);
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'position']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_results');
    }
};
