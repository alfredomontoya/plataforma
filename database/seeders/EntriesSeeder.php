<?php

namespace Database\Seeders;

use App\Models\Entry;
use App\Models\Service;
use App\Models\User;
use App\Support\Mulberry32;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Siembra la tabla entries. Tres modos excluyentes (legacy, per-user, total).
 *
 * Parámetros:
 * - month (string|null, default '2026-08'): mes YYYY-MM a sembrar (legacy/per-user).
 *   Se ignora si se pasa from.
 * - from (string|null, default null): inicio de rango YYYY-MM-DD (legacy/per-user).
 *   Sin from ni month → 1 día (hoy UTC).
 * - to (string|null, default null): fin de rango YYYY-MM-DD inclusivo (legacy/per-user).
 *   Default: hoy cuando hay from.
 * - min (int, default 3): cantidad mínima en día hábil.
 * - max (int, default 10): cantidad máxima en día hábil.
 * - weekendMin (int, default 1): cantidad mínima en fin de semana.
 * - weekendMax (int, default 3): cantidad máxima en fin de semana.
 * - perUserMin (int|null, default null): activa el modo per-user; filas mín. por usuario/día.
 * - perUserMax (int|null, default null): filas máx. por usuario/día (dar junto a perUserMin).
 * - allUsers (bool, default true): incluye ADMIN (puede registrar ambos tipos).
 * - includeJefe (bool, default true): incluye JEFE (solo demo: la API real lo rechaza).
 * - seed (int|null, default null): semilla PRNG para datos reproducibles.
 *   Default: año*100+mes (legacy/per-user) o AAAAMMDD del inicio (total).
 * - clear (bool, default false): true = vacía entries antes de sembrar.
 * - total (int|null, default null): activa el modo total; n registros exactos
 *   repartidos equitativamente por día.
 * - fechaini (string|null, default null): fecha inicial YYYY-MM-DD del modo total.
 *   Default: 1er día del mes actual UTC.
 * - fechafin (string|null, default null): fecha final YYYY-MM-DD inclusiva del modo total.
 *   Default: hoy UTC.
 *
 * Modos:
 * - Legacy (perUserMin = null, total = null): 1 fila por día×servicio, round-robin
 *   de operadores con orden mezclado por día. Rango máx 366 días. (Original.)
 * - Per-user (perUserMin/perUserMax no nulos): n filas por usuario y día, sin repetir
 *   servicios. n se sortea en [perUserMin, perUserMax] y se topa a los servicios
 *   elegibles (ENTREGA solo tiene 3).
 * - Total (total no nulo): genera exactamente n registros entre fechaini y fechafin.
 *   Cada día recibe floor(n/días); los n%días restantes se reparten (+1) en días
 *   sorteados. Dentro del día, usuario y servicio al azar sin repetir (usuario, servicio).
 *   Sin tope de días (el costo depende de n, no del rango).
 *
 * Elegibles por rol (por defecto entran todos, nadie queda fuera):
 * OPERATOR_INGRESO→INGRESO, OPERATOR_ENTREGA→ENTREGA, ADMIN→todos, JEFE→todos.
 *
 * Ejemplos (vía platform:entries-seed):
 * - Total, 500 registros del 01/06/2025 a hoy, limpiando antes:
 *     php artisan platform:entries-seed --total=500 --fechaini=2025-06-01 --clear --force
 * - Total con defaults (1er día del mes actual → hoy), sin limpiar:
 *     php artisan platform:entries-seed --total=100
 * - Per-user, 5-10 filas por usuario/día del mes dado, limpiando antes
 *   (entran todos por defecto; --operators-only / --excludeJefe para recortar):
 *     php artisan platform:entries-seed --month=2026-08 --perUserMin=5 --perUserMax=10 --clear --force
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
        private bool $allUsers = true,
        private bool $includeJefe = true,
        private ?int $seed = null,
        private bool $clear = false,
        private ?int $total = null,
        private ?string $fechaini = null,
        private ?string $fechafin = null,
    ) {}

    public function run(): void
    {
        if ($this->clear) {
            Entry::query()->delete();
        }

        if ($this->total !== null) {
            $this->validateTotal();
            $this->runTotal();

            return;
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

    /** Modo total: n registros repartidos de forma equitativa por día. */
    private function runTotal(): void
    {
        [$start, $end] = $this->totalBounds();
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

        $days = (int) $start->diffInDays($end);
        $base = intdiv($this->total, $days);
        $extra = $this->total % $days;

        $rng = new Mulberry32($this->seed ?? $this->totalSeedBase($start));
        $extraDays = array_flip(array_slice($rng->shuffle(range(0, $days - 1)), 0, $extra));

        $rows = [];
        $cursor = $start->copy();
        for ($i = 0; $i < $days; $i++) {
            $need = $base + (isset($extraDays[$i]) ? 1 : 0);
            $used = [];
            $placed = 0;
            $guard = max($need * 30, 30);
            $weekend = $cursor->isWeekend();
            $day = $cursor->copy()->startOfDay();

            while ($placed < $need && $guard-- > 0) {
                $user = $users[$rng->int(0, $users->count() - 1)];
                $eligible = $this->eligibleServices($user->role, $services, $byType);
                if ($eligible->isEmpty()) {
                    continue;
                }
                $service = $eligible[$rng->int(0, $eligible->count() - 1)];
                $key = $user->id . '|' . $service->id;
                if (isset($used[$key])) {
                    continue;
                }
                $used[$key] = true;
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
                $placed++;
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

    /** [inicio UTC inclusivo, fin UTC exclusivo] del modo total. */
    private function totalBounds(): array
    {
        $ini = $this->fechaini ?? Carbon::now('UTC')->startOfMonth()->toDateString();
        $fin = $this->fechafin ?? Carbon::now('UTC')->toDateString();
        $start = Carbon::createFromFormat('Y-m-d', $ini, 'UTC')->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $fin, 'UTC')->startOfDay()->addDay();

        return [$start, $end];
    }

    private function totalSeedBase(Carbon $start): int
    {
        return ($start->year * 10000 + $start->month * 100 + $start->day) & 0xFFFFFFFF;
    }

    private function validateTotal(): void
    {
        if ($this->total < 1) {
            throw new \InvalidArgumentException('El total debe ser >= 1.');
        }
        if ($this->min > $this->max || $this->weekendMin > $this->weekendMax) {
            throw new \InvalidArgumentException('Rangos de quantity inválidos (min <= max).');
        }
        try {
            [$start, $end] = $this->totalBounds();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('fechaini/fechafin deben tener formato YYYY-MM-DD.');
        }
        if ($start->gte($end)) {
            throw new \InvalidArgumentException('fechaini no puede ser posterior a fechafin.');
        }
        // Sin tope de 366 días: el costo del modo total depende de n, no de los días.
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
