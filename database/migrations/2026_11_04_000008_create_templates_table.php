<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
