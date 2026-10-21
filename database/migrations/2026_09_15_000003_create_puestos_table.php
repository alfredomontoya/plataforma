<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puestos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('areaId', 24);
            $table->foreign('areaId')->references('id')->on('areas')->restrictOnDelete();
            $table->string('nombre', 191);
            $table->string('sigla', 10)->nullable();
            // Escala 1..7: 1 = operativo más bajo, 7 = Jefe de Área.
            $table->unsignedTinyInteger('nivel');
            $table->boolean('esSupervisor')->default(false);
            $table->timestamps();

            $table->index(['areaId', 'nivel']);
            $table->index(['areaId', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puestos');
    }
};
