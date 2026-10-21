<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('tratamientoId', 24)->nullable();
            $table->foreign('tratamientoId')->references('id')->on('tratamientos')->restrictOnDelete();
            $table->string('ci', 20)->unique();
            $table->string('nombre', 100);
            $table->string('apellidos', 100);
            $table->date('fechaNacimiento')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->text('direccion')->nullable();
            $table->string('profesion', 100)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo')->index();
            // Auditoría: empleado que creó este registro.
            $table->char('creadoPorEmpleadoId', 24)->nullable();
            $table->foreign('creadoPorEmpleadoId')->references('id')->on('empleados')->nullOnDelete();
            $table->timestamps();

            $table->index('apellidos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
