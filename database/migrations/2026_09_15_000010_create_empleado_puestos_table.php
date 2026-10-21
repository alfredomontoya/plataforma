<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleado_puestos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('empleadoId', 24);
            $table->foreign('empleadoId')->references('id')->on('empleados')->restrictOnDelete();
            $table->char('puestoId', 24);
            $table->foreign('puestoId')->references('id')->on('puestos')->restrictOnDelete();
            $table->date('fechaInicio');
            // NULL = puesto activo actual del empleado.
            $table->date('fechaFin')->nullable();
            // Supervisor directo del empleado en este puesto.
            $table->char('supervisorId', 24)->nullable();
            $table->foreign('supervisorId')->references('id')->on('empleados')->nullOnDelete();
            $table->timestamps();

            $table->index(['empleadoId', 'fechaFin']);
            $table->index('puestoId');
            $table->index('supervisorId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_puestos');
    }
};
