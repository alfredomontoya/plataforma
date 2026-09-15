<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('codigo', 32)->nullable()->unique()->after('abreviation');
        });

        // codigo hereda la abreviación corta actual; abreviation queda libre
        // para la abreviatura real del servicio.
        DB::table('services')->whereNull('codigo')->update(['codigo' => DB::raw('abreviation')]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
