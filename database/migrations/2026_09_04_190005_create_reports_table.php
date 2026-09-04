<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('nroCI', 50)->index();
            $table->string('dirigidoA', 200);
            $table->string('puestoDirigidoA', 200);
            $table->char('templateId', 24);
            $table->foreign('templateId')->references('id')->on('templates')->restrictOnDelete();
            $table->string('mode', 8)->index();
            $table->dateTime('periodStart');
            $table->dateTime('periodEnd');
            $table->string('fileName', 191);
            $table->text('filePath');
            $table->unsignedInteger('totalIngreso')->default(0);
            $table->unsignedInteger('totalEntrega')->default(0);
            $table->char('generatedById', 24);
            $table->foreign('generatedById')->references('id')->on('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
