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
        Schema::create('tipo_registros', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name', 100)->unique();
            $table->string('codigo', 16)->unique();
            $table->boolean('requiereItems')->default(false);
            $table->boolean('isActive')->default(true)->index();
            $table->integer('sortOrder')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('tipo_registros')->insert([
            [
                'id' => MongoId::generate(),
                'name' => 'INGRESO',
                'codigo' => 'INGRESO',
                'requiereItems' => false,
                'isActive' => true,
                'sortOrder' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => MongoId::generate(),
                'name' => 'ENTREGA',
                'codigo' => 'ENTREGA',
                'requiereItems' => true,
                'isActive' => true,
                'sortOrder' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_registros');
    }
};
