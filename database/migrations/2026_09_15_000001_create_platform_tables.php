<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline agrupado de tablas propias (squash 2026-09-15 de las migraciones
 * 0001_01_01_000000, 2026_09_04_190001..190006). Esquema idéntico al original.
 * Infraestructura/vendor (cache, jobs, tokens, permissions) queda en sus archivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->string('role', 32)->index();
            $table->string('title', 8)->default('NONE');
            $table->string('firstName', 100);
            $table->string('lastName', 100);
            $table->string('paternalSurname', 100)->nullable();
            $table->string('maternalSurname', 100)->nullable();
            $table->boolean('isActive')->default(true)->index();
            $table->boolean('canBackfill')->default(false);
            $table->date('canBackfillEnabledAt')->nullable();
            // FK a positions se agrega abajo (circular users <-> positions)
            $table->char('activePositionId', 24)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('username', 50)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->char('user_id', 24)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

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

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('activePositionId')->references('id')->on('positions')->nullOnDelete();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name', 191)->unique();
            $table->string('abreviation', 32)->nullable();
            $table->string('type', 16)->index();
            $table->boolean('isActive')->default(true)->index();
            $table->integer('sortOrder')->default(0);
            $table->timestamps();
        });

        Schema::create('entries', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('userId', 24);
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->char('serviceId', 24);
            $table->foreign('serviceId')->references('id')->on('services')->cascadeOnDelete();
            // Día calendario en medianoche UTC (nunca hora local)
            $table->dateTime('date')->index();
            $table->string('type', 16);
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->index(['date', 'type']);
            $table->index(['userId', 'date']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name', 191);
            $table->string('fileName', 191);
            $table->text('filePath');
            $table->char('uploadedById', 24);
            $table->foreign('uploadedById')->references('id')->on('users')->cascadeOnDelete();
            $table->boolean('isDefault')->default(false)->index();
            $table->timestamps();
        });

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
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['activePositionId']);
        });
        Schema::dropIfExists('reports');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('entries');
        Schema::dropIfExists('services');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
