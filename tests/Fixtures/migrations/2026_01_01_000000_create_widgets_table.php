<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('colour')->nullable();
            $table->boolean('broken')->default(false);
            $table->boolean('explode')->default(false);
            $table->boolean('advisory_broken')->default(false);
            $table->boolean('warn')->default(false);
            $table->boolean('secret')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
