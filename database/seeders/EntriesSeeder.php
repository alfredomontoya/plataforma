<?php

namespace Database\Seeders;

use App\Models\Entry;
use App\Models\Service;
use App\Models\User;
use App\Support\Mulberry32;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Entradas deterministas en dos modos:
 *
 * - Legacy (perUserMin = null): mes (--month) o rango (--from/--to, máx 366 días).
 *   Una fila por día×servicio; reparto round-robin con orden mezclado por día;
 *   semilla año*100+mes re-sembrada por mes. (Comportamiento original.)
 *
 * - Per-user (perUserMin/perUserMax no nulos):
 *   - Sin fecha (sin --from ni --month) → 1 día (hoy UTC), n filas por usuario.
 *   - Con --from/--to (o --month) → n filas por día para cada usuario.
 *   n se sortea por usuario y día en [perUserMin, perUserMax] y se topa al
 *   nº de servicios elegibles (ENTREGA solo tiene 3). Muestreo sin repetición.
 *   Elegibles: OPERATOR_INGRESO→INGRESO, OPERATOR_ENTREGA→ENTREGA,
 *   ADMIN→todos, JEFE→todos solo con includeJefe (demo: la API real lo rechaza).
 */
class EntriesSeeder extends Seeder
{
    public function __construct(
        private ?string $month = '2026-08',
        private ?string $from = null,
        private ?string $to = null,
        private int $min = 3,
        private int $max = 10,
        private int $weekendMin = 1,
        private int $weekendMax = 3,
        private ?int $perUserMin = null,
        private ?int $perUserMax = null,
        private bool $allUsers = false,
        private bool $includeJefe = false,
        private ?int $seed = null,
        private bool $clean = true,
    ) {}

    public function run(): void
    {
        if ($this->clean) {
            Entry::query()->delete();
        }

        [$start, $end] = $this->bounds();
        $days = (int) $start->diffInDays($end);
        if ($days > 366) {
            throw new \InvalidArgumentException('El rango no puede superar 366 días.');
        }
        if ($this->perUserMin !== null) {
            $this->validatePerUser();
            $this->runPerUser($start, $end);

            return;
        }

        $this->runLegacy($start, $end);
    }

    /** Modo original: 1 fila por día×servicio con round-robin de operadores. */
    private function runLegacy(Carbon $start, Carbon $end): void
    {
        $services = Service::where('isActive', true)->orderBy('sortOrder')->get();
        $opsIng = User::where('role', 'OPERATOR_INGRESO')->where('isActive', true)->orderBy('username')->get();
        $opsEnt = User::where('role', 'OPERATOR_ENTREGA')->where('isActive', true)->orderBy('username')->get();
        if ($services->isEmpty() || $opsIng->isEmpty() || $opsEnt->isEmpty()) {
            return;
        }

        $cursor = $start->copy();
        $currentMonthKey = null;
        $rng = null;
        $rows = [];
        while ($cursor->lt($end)) {
            $monthKey = $cursor->year * 100 + $cursor->month;
            if ($monthKey !== $currentMonthKey) {
                $currentMonthKey = $monthKey;
                $rng = new Mulberry32($this->seedBase($monthKey));
            }
            $weekend = $cursor->isWeekend();
            $day = $cursor->copy()->startOfDay();

            foreach (['INGRESO' => $opsIng, 'ENTREGA' => $opsEnt] as $type => $ops) {
                $order = $rng->shuffle($ops->all());
                $i = 0;
                foreach ($services->where('type', $type) as $service) {
                    // sorteo incondicional aunque el servicio se salte (idempotencia)
                    $qty = $weekend ? $rng->int($this->weekendMin, $this->weekendMax) : $rng->int($this->min, $this->max);
                    $rows[] = [
                        'id' => \App\Support\MongoId::generate(),
                        'userId' => $order[$i % count($order)]->id,
                        'serviceId' => $service->id,
                        'date' => $day,
                        'type' => $type,
                        'quantity' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $i++;
                }
            }
            $cursor->addDay();
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            Entry::insert($chunk);
        }
    }

    /** Modo per-user: n filas por día para cada usuario, servicios sin repetir. */
    private function runPerUser(Carbon $start, Carbon $end): void
    {
        $services = Service::where('isActive', true)->orderBy('sortOrder')->get();
        if ($services->isEmpty()) {
            return;
        }
        $byType = [
            'INGRESO' => $services->where('type', 'INGRESO')->values(),
            'ENTREGA' => $services->where('type', 'ENTREGA')->values(),
        ];

        $roles = ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA'];
        if ($this->allUsers) {
            $roles[] = 'ADMIN';
        }
        if ($this->includeJefe) {
            $roles[] = 'JEFE';
        }
        $users = User::whereIn('role', $roles)->where('isActive', true)->orderBy('username')->get();
        if ($users->isEmpty()) {
            return;
        }

        $cursor = $start->copy();
        $currentMonthKey = null;
        $rng = null;
        $rows = [];
        while ($cursor->lt($end)) {
            $monthKey = $cursor->year * 100 + $cursor->month;
            if ($monthKey !== $currentMonthKey) {
                $currentMonthKey = $monthKey;
                $rng = new Mulberry32($this->seedBase($monthKey));
            }
            $weekend = $cursor->isWeekend();
            $day = $cursor->copy()->startOfDay();

            foreach ($users as $user) {
                $eligible = $this->eligibleServices($user->role, $services, $byType);
                if ($eligible->isEmpty()) {
                    continue;
                }
                $n = $rng->int($this->perUserMin, $this->perUserMax);
                $n = min($n, $eligible->count());
                $picked = array_slice($rng->shuffle($eligible->all()), 0, $n);

                foreach ($picked as $service) {
                    $qty = $weekend ? $rng->int($this->weekendMin, $this->weekendMax) : $rng->int($this->min, $this->max);
                    $rows[] = [
                        'id' => \App\Support\MongoId::generate(),
                        'userId' => $user->id,
                        'serviceId' => $service->id,
                        'date' => $day,
                        'type' => $service->type,
                        'quantity' => $qty,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
            $cursor->addDay();
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            Entry::insert($chunk);
        }
    }

    private function eligibleServices(string $role, $all, array $byType)
    {
        return match ($role) {
            'OPERATOR_INGRESO' => $byType['INGRESO'],
            'OPERATOR_ENTREGA' => $byType['ENTREGA'],
            // ADMIN y JEFE (demo) pueden registrar ambos tipos.
            default => $all,
        };
    }

    private function seedBase(int $monthKey): int
    {
        return $this->seed !== null ? ($this->seed + $monthKey) & 0xFFFFFFFF : $monthKey;
    }

    private function validatePerUser(): void
    {
        if ($this->perUserMin < 1 || $this->perUserMax < 1) {
            throw new \InvalidArgumentException('perUserMin/perUserMax deben ser >= 1.');
        }
        if ($this->perUserMin > $this->perUserMax) {
            throw new \InvalidArgumentException('perUserMin no puede ser mayor que perUserMax.');
        }
        if ($this->min > $this->max || $this->weekendMin > $this->weekendMax) {
            throw new \InvalidArgumentException('Rangos de quantity inválidos (min <= max).');
        }
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
        // Modo A sin parámetros: 1 día (hoy UTC), n filas por usuario.
        $start = Carbon::now('UTC')->startOfDay();
        return [$start, $start->copy()->addDay()];
    }
}
