<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when a fix changes the record after the run finished, so the run
        // no longer describes the record even if the record's own timestamp
        // did not move.
        Schema::table('diagnostic_runs', function (Blueprint $table) {
            $table->timestamp('fixed_at')->nullable()->after('finished_at');
        });

        Schema::table('diagnostic_results', function (Blueprint $table) {
            $table->boolean('fixable')->default(false)->after('error');
            $table->string('fix_label', 64)->nullable()->after('fixable');
            $table->timestamp('fixed_at')->nullable()->after('fix_label');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_runs', function (Blueprint $table) {
            $table->dropColumn('fixed_at');
        });

        Schema::table('diagnostic_results', function (Blueprint $table) {
            $table->dropColumn(['fixable', 'fix_label', 'fixed_at']);
        });
    }
};
