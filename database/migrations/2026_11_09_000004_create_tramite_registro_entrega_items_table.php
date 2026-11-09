<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tramite_registro_entrega_items', function (Blueprint $table) {
            $table->char('tramiteRegistroId', 24);
            $table->char('entregaItemId', 24);
            $table->primary(['tramiteRegistroId', 'entregaItemId']);

            $table->foreign('tramiteRegistroId')->references('id')->on('tramite_registros')->cascadeOnDelete();
            $table->foreign('entregaItemId')->references('id')->on('entrega_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tramite_registro_entrega_items');
    }
};
