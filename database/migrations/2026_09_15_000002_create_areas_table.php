<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('nombre', 191)->unique();
            $table->string('codigo', 30)->nullable()->unique();
            $table->text('descripcion')->nullable();
            $table->char('padreId', 24)->nullable();
            $table->foreign('padreId')->references('id')->on('areas')->restrictOnDelete();
            $table->index('padreId');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
