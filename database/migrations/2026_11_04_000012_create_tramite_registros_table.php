<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_registros', function (Blueprint $table) {
            $table->char('id', 24)->primary();

            $table->char('userId', 24);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();

            // Tipo de trámite = servicio activo del catálogo.
            $table->char('serviceId', 24);
            $table->foreign('serviceId')->references('id')->on('services')->cascadeOnDelete();

            // Día calendario en medianoche UTC (nunca hora local).
            $table->dateTime('date')->index();

            // Nro. de placa (3/4 dígitos + 3 letras) o póliza (5-10 dígitos).
            $table->string('placa', 30);
            $table->string('observaciones', 500)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['date', 'activo']);
            $table->index(['userId', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_registros');
    }
};
