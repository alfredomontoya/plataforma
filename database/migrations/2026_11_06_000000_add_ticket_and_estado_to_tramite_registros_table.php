<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramite_registros', function (Blueprint $table) {
            // Nro. de ticket alfanumérico (máximo 8 caracteres).
            $table->string('ticket', 8)->nullable()->after('observaciones');
            $table->enum('estado', ['procesado', 'recepcionado'])
                ->default('procesado')
                ->after('ticket');
        });
    }

    public function down(): void
    {
        Schema::table('tramite_registros', function (Blueprint $table) {
            $table->dropColumn(['ticket', 'estado']);
        });
    }
};
