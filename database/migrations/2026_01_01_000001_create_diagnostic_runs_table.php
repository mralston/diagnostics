<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->string('suite', 64);
            $table->string('subject_type', 191);
            // A string so integer and UUID keys are stored the same way.
            $table->string('subject_id', 64);
            $table->string('status', 16)->default('pending');
            $table->string('phase', 16)->default('parallel');
            $table->string('outcome', 24)->nullable();
            $table->string('executor', 8)->default('queued');
            $table->string('triggered_by', 16)->default('http');
            $table->string('triggered_by_id', 64)->nullable();
            $table->unsignedSmallInteger('total_checks')->default(0);
            $table->unsignedSmallInteger('passed_count')->default(0);
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedSmallInteger('skipped_count')->default(0);
            $table->unsignedSmallInteger('errored_count')->default(0);
            $table->smallInteger('pending_batches')->default(0);
            $table->timestamp('subject_updated_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['suite', 'subject_type', 'subject_id', 'id'], 'diagnostic_runs_subject_index');
            $table->index(['status', 'last_activity_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_runs');
    }
};
