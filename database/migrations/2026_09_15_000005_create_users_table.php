<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->string('email', 191)->nullable()->unique();
            $table->string('role', 32)->index();
            $table->boolean('isActive')->default(true)->index();
            $table->boolean('canBackfill')->default(false);
            $table->date('canBackfillEnabledAt')->nullable();
            // Vínculo 1:1 obligatorio con la ficha de RRHH.
            $table->char('empleadoId', 24)->unique();
            $table->foreign('empleadoId')->references('id')->on('empleados')->restrictOnDelete();
            // Auditoría de creación de la cuenta de sistema.
            $table->char('creadoPorUsuarioId', 24)->nullable();
            $table->foreign('creadoPorUsuarioId')->references('id')->on('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
