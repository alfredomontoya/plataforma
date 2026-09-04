<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name', 191)->unique();
            $table->string('abreviation', 32)->nullable();
            $table->string('type', 16)->index();
            $table->boolean('isActive')->default(true)->index();
            $table->integer('sortOrder')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
