<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de RRHH: tratamientos, áreas, puestos (jerarquía), empleados
 * (auditoría de creación) y empleado_puestos (historial/rotación de cargos).
 * También extiende `users` con email + vínculo 1:1 a empleado + auditoría.
 *
 * Convención del proyecto: PK char(24) generadas por HasMongoId, columnas
 * camelCase. El puesto activo actual de un empleado es la asignación con
 * fechaFin IS NULL; la unicidad de esa fila NO es un índice único porque
 * MySQL permite múltiples NULL en índices únicos — se garantiza en
 * EmpleadoPuestoService dentro de DB::transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tratamientos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('nombre', 50)->unique();
            $table->string('abreviatura', 10);
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('nombre', 191)->unique();
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('puestos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('areaId', 24);
            $table->foreign('areaId')->references('id')->on('areas')->restrictOnDelete();
            $table->string('nombre', 191);
            // Escala 1..7: 1 = operativo más bajo, 7 = Jefe de Área.
            $table->unsignedTinyInteger('nivel');
            $table->boolean('esSupervisor')->default(false);
            $table->timestamps();

            $table->index(['areaId', 'nivel']);
            $table->index(['areaId', 'nombre']);
        });

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
            // Auditoría: qué operador (empleado) creó este registro; nullOnDelete
            // para que la auditoría nunca bloquee un borrado futuro.
            $table->char('creadoPorEmpleadoId', 24)->nullable();
            $table->foreign('creadoPorEmpleadoId')->references('id')->on('empleados')->nullOnDelete();
            $table->timestamps();

            $table->index('apellidos');
        });

        Schema::create('empleado_puestos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('empleadoId', 24);
            $table->foreign('empleadoId')->references('id')->on('empleados')->restrictOnDelete();
            $table->char('puestoId', 24);
            $table->foreign('puestoId')->references('id')->on('puestos')->restrictOnDelete();
            $table->date('fechaInicio');
            // NULL = puesto activo actual del empleado.
            $table->date('fechaFin')->nullable();
            // Supervisor directo del empleado en ESTE puesto (ej. los operadores
            // de ingreso reportan al encargado de ingreso).
            $table->char('supervisorId', 24)->nullable();
            $table->foreign('supervisorId')->references('id')->on('empleados')->nullOnDelete();
            $table->timestamps();

            // Búsqueda del puesto activo: WHERE empleadoId = ? AND fechaFin IS NULL.
            $table->index(['empleadoId', 'fechaFin']);
            $table->index('puestoId');
            $table->index('supervisorId');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email', 191)->nullable()->unique()->after('password');
            // 1:1 opcional con empleado (unique garantiza "un usuario por empleado").
            $table->char('empleadoId', 24)->nullable()->unique();
            $table->foreign('empleadoId')->references('id')->on('empleados')->restrictOnDelete();
            // Auditoría de creación de la cuenta de sistema.
            $table->char('creadoPorUsuarioId', 24)->nullable();
            $table->foreign('creadoPorUsuarioId')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['creadoPorUsuarioId']);
            $table->dropForeign(['empleadoId']);
            $table->dropUnique(['empleadoId']);
            $table->dropColumn(['empleadoId', 'creadoPorUsuarioId', 'email']);
        });

        Schema::dropIfExists('empleado_puestos');
        Schema::dropIfExists('empleados');
        Schema::dropIfExists('puestos');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('tratamientos');
    }
};
