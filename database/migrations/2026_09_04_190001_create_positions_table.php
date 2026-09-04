<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('userId', 24);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->string('title', 8)->default('NONE');
            $table->string('position', 191);
            $table->string('department', 191)->nullable();
            $table->boolean('isActive')->default(true);
            $table->dateTime('startDate');
            $table->dateTime('endDate')->nullable();
            $table->timestamps();

            $table->index(['userId', 'isActive']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
