<?php

use App\Support\MongoId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entrega_items', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name', 100)->unique();
            $table->string('codigo', 16)->unique();
            $table->boolean('isActive')->default(true)->index();
            $table->integer('sortOrder')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('entrega_items')->insert([
            [
                'id' => MongoId::generate(),
                'name' => 'PLACA',
                'codigo' => 'PL',
                'isActive' => true,
                'sortOrder' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => MongoId::generate(),
                'name' => 'CERTIFICADO DE PROPIEDAD',
                'codigo' => 'CP',
                'isActive' => true,
                'sortOrder' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => MongoId::generate(),
                'name' => 'PLAQUETA',
                'codigo' => 'PQ',
                'isActive' => true,
                'sortOrder' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('entrega_items');
    }
};
