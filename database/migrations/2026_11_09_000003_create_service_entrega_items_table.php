<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_entrega_items', function (Blueprint $table) {
            $table->char('serviceId', 24);
            $table->char('entregaItemId', 24);
            $table->primary(['serviceId', 'entregaItemId']);

            $table->foreign('serviceId')->references('id')->on('services')->cascadeOnDelete();
            $table->foreign('entregaItemId')->references('id')->on('entrega_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_entrega_items');
    }
};
