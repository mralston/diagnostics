<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_fixes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('diagnostic_runs')->cascadeOnDelete();
            $table->foreignId('result_id')->constrained('diagnostic_results')->cascadeOnDelete();
            $table->string('check_class', 191);
            $table->string('status', 16);
            $table->string('outcome_before', 16)->nullable();
            $table->string('outcome_after', 16)->nullable();
            $table->json('answers')->nullable();
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->string('fixed_by', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['result_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_fixes');
    }
};
