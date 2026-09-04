<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
