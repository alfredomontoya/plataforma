<?php

namespace Database\Seeders;

use App\Models\EntregaItem;
use App\Models\Service;
use App\Models\TipoRegistro;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Support\MongoId;
use App\Support\Mulberry32;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra demo de `tramite_registros` (un registro = un trámite).
 * Reemplaza a EntriesSeeder/TotalsSeeder como fuente de los gráficos.
 *
 * Parámetros:
 * - month (YYYY-MM): mes a sembrar (se ignora con from).
 * - from / to (YYYY-MM-DD): rango inclusivo. Sin ambos → hoy UTC.
 * - minPerDay / maxPerDay: trámites por día.
 * - seed: semilla PRNG para datos reproducibles.
 * - clear: vacía tramite_registros antes de sembrar.
 */
class TramitesSeeder extends Seeder
{
    private const LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private ?string $month = null,
        private ?string $from = null,
        private ?string $to = null,
        private int $minPerDay = 3,
        private int $maxPerDay = 12,
        private ?int $seed = null,
        private bool $clear = false,
    ) {}

    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('TramitesSeeder omitido: no se siembran trámites demo en producción.');

            return;
        }

        if ($this->clear) {
            TramiteRegistro::query()->delete();
        }

        [$start, $end] = $this->bounds();

        $services = Service::where('isActive', true)->orderBy('sortOrder')->get();
        if ($services->isEmpty()) {
            return;
        }

        $tipos = TipoRegistro::where('isActive', true)->get();
        $items = EntregaItem::where('isActive', true)->get();
        if ($tipos->isEmpty()) {
            return;
        }

        $users = User::whereIn('role', ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN'])
            ->where('isActive', true)
            ->orderBy('username')
            ->get();
        if ($users->isEmpty()) {
            return;
        }

        $rng = new Mulberry32($this->seed ?? 20261104);
        $rows = [];
        $itemRows = [];
        $cursor = $start->copy();

        while ($cursor->lt($end)) {
            $day = $cursor->copy()->startOfDay();
            $n = $rng->int($this->minPerDay, $this->maxPerDay);

            for ($i = 0; $i < $n; $i++) {
                $user = $users[$rng->int(0, $users->count() - 1)];
                $service = $services[$rng->int(0, $services->count() - 1)];
                $tipo = $tipos[$rng->int(0, $tipos->count() - 1)];
                $createdAt = $day->copy()->addSeconds($rng->int(0, 86399));
                $id = MongoId::generate();

                $rows[] = [
                    'id' => $id,
                    'userId' => $user->id,
                    'serviceId' => $service->id,
                    'tipoRegistroId' => $tipo->id,
                    'date' => $day,
                    'placa' => $this->plate($rng),
                    'ticket' => 'TK'.str_pad((string) $rng->int(0, 999999), 6, '0', STR_PAD_LEFT),
                    'estado' => $rng->next() < 0.5 ? 'procesado' : 'recepcionado',
                    'observaciones' => null,
                    'activo' => true,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if ($tipo->requiereItems && $items->isNotEmpty()) {
                    $selected = $items->random(min(2, $items->count()));
                    foreach ($selected as $item) {
                        $itemRows[] = [
                            'tramiteRegistroId' => $id,
                            'entregaItemId' => $item->id,
                        ];
                    }
                }
            }

            $cursor->addDay();
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            TramiteRegistro::insert($chunk);
        }

        foreach (array_chunk($itemRows, 1000) as $chunk) {
            DB::table('tramite_registro_entrega_items')->insertOrIgnore($chunk);
        }
    }

    /** Placa/póliza demo: 4 dígitos + 3 letras (ej. 1234ABC). */
    private function plate(Mulberry32 $rng): string
    {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
            $out .= (string) $rng->int(0, 9);
        }
        for ($i = 0; $i < 3; $i++) {
            $out .= self::LETTERS[$rng->int(0, strlen(self::LETTERS) - 1)];
        }

        return $out;
    }

    /** [inicio UTC inclusivo, fin UTC exclusivo]. */
    private function bounds(): array
    {
        if ($this->from) {
            $start = Carbon::createFromFormat('Y-m-d', $this->from, 'UTC')->startOfDay();
            $end = $this->to
                ? Carbon::createFromFormat('Y-m-d', $this->to, 'UTC')->startOfDay()->addDay()
                : Carbon::now('UTC')->startOfDay()->addDay();

            return [$start, $end];
        }

        if ($this->month) {
            $start = Carbon::createFromFormat('Y-m', $this->month, 'UTC')->startOfMonth();

            return [$start, $start->copy()->addMonth()];
        }

        $start = Carbon::now('UTC')->startOfDay();

        return [$start, $start->copy()->addDay()];
    }
}
