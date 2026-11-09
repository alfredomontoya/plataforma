<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tramite_registros', function (Blueprint $table) {
            $table->char('tipoRegistroId', 24)->nullable()->after('serviceId');
            $table->index('tipoRegistroId');
        });

        $this->backfillTipoRegistro();
        $this->backfillLegacyItems();
        $this->deactivateLegacyServices();
        $this->seedServiceItems();

        // SQLite no permite agregar claves foráneas con ALTER TABLE.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tramite_registros', function (Blueprint $table) {
                $table->foreign('tipoRegistroId')
                    ->references('id')
                    ->on('tipo_registros')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tramite_registros', function (Blueprint $table) {
                $table->dropForeign(['tipoRegistroId']);
            });
        }

        Schema::table('tramite_registros', function (Blueprint $table) {
            $table->dropIndex(['tipoRegistroId']);
            $table->dropColumn('tipoRegistroId');
        });
    }

    private function backfillTipoRegistro(): void
    {
        $tipos = DB::table('tipo_registros')->pluck('id', 'codigo');

        if ($tipos->isEmpty()) {
            return;
        }

        DB::table('tramite_registros')
            ->select('id', 'serviceId')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($tipos) {
                $types = DB::table('services')
                    ->whereIn('id', $rows->pluck('serviceId')->unique()->all())
                    ->pluck('type', 'id');

                foreach ($rows as $row) {
                    $tipoId = $tipos[$types[$row->serviceId] ?? ''] ?? null;

                    if ($tipoId !== null) {
                        DB::table('tramite_registros')
                            ->where('id', $row->id)
                            ->update(['tipoRegistroId' => $tipoId]);
                    }
                }
            });
    }

    /**
     * Los servicios históricos de tipo ENTREGA representaban ítems entregables:
     * CP = certificado, PM = placa metálica, PQ = plaqueta.
     */
    private function backfillLegacyItems(): void
    {
        $items = DB::table('entrega_items')->pluck('id', 'codigo');
        $legacy = DB::table('services')
            ->where('type', 'ENTREGA')
            ->whereIn('codigo', ['CP', 'PM', 'PQ'])
            ->pluck('codigo', 'id');

        if ($legacy->isEmpty() || $items->isEmpty()) {
            return;
        }

        $map = ['CP' => 'CP', 'PM' => 'PL', 'PQ' => 'PQ'];

        DB::table('tramite_registros')
            ->whereIn('serviceId', $legacy->keys()->all())
            ->select('id', 'serviceId')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($legacy, $items, $map) {
                $pivot = [];

                foreach ($rows as $row) {
                    $serviceCode = $legacy[$row->serviceId] ?? null;
                    $itemId = $serviceCode !== null ? ($items[$map[$serviceCode] ?? ''] ?? null) : null;

                    if ($itemId !== null) {
                        $pivot[] = [
                            'tramiteRegistroId' => $row->id,
                            'entregaItemId' => $itemId,
                        ];
                    }
                }

                if ($pivot !== []) {
                    DB::table('tramite_registro_entrega_items')->insertOrIgnore($pivot);
                }
            });
    }

    private function deactivateLegacyServices(): void
    {
        DB::table('services')
            ->where('type', 'ENTREGA')
            ->whereIn('codigo', ['CP', 'PM', 'PQ'])
            ->update(['isActive' => false]);
    }

    private function seedServiceItems(): void
    {
        $items = DB::table('entrega_items')->pluck('id', 'codigo');

        if ($items->isEmpty()) {
            return;
        }

        $services = DB::table('services')->get(['id', 'name']);
        $rows = [];

        foreach ($services as $service) {
            $codes = match (true) {
                str_starts_with($service->name, 'INSCRIPCION') => ['PL', 'PQ', 'CP'],
                str_starts_with($service->name, 'TRANSFERENCIA') => ['CP'],
                $service->name === 'CAMBIO DE RADICATORIA' => ['PQ', 'CP'],
                default => [],
            };

            foreach ($codes as $code) {
                if ($items->has($code)) {
                    $rows[] = [
                        'serviceId' => $service->id,
                        'entregaItemId' => $items[$code],
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('service_entrega_items')->insertOrIgnore($rows);
        }
    }
};
