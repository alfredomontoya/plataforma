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
        Schema::table('tipo_registros', function (Blueprint $table) {
            $table->boolean('requiereTicket')->default(true)->after('requiereItems');
        });

        // Los tipos existentes mantienen el ticket obligatorio.
        DB::table('tipo_registros')
            ->whereIn('codigo', ['INGRESO', 'ENTREGA'])
            ->update(['requiereTicket' => true]);

        // CIERRE se comporta como INGRESO, pero sin ticket obligatorio.
        if (! DB::table('tipo_registros')->where('codigo', 'CIERRE')->exists()) {
            $now = now();

            DB::table('tipo_registros')->insert([
                'id' => MongoId::generate(),
                'name' => 'CIERRE',
                'codigo' => 'CIERRE',
                'requiereItems' => false,
                'requiereTicket' => false,
                'isActive' => true,
                'sortOrder' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $cierre = DB::table('tipo_registros')->where('codigo', 'CIERRE')->first();

        if ($cierre) {
            $hasRegistros = DB::table('tramite_registros')
                ->where('tipoRegistroId', $cierre->id)
                ->exists();

            if ($hasRegistros) {
                // No se elimina un tipo con registros: se desactiva.
                DB::table('tipo_registros')
                    ->where('id', $cierre->id)
                    ->update(['isActive' => false]);
            } else {
                DB::table('tipo_registros')->where('id', $cierre->id)->delete();
            }
        }

        Schema::table('tipo_registros', function (Blueprint $table) {
            $table->dropColumn('requiereTicket');
        });
    }
};
