<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
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
            // FK a positions se agrega en migración posterior (circular users <-> positions)
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
