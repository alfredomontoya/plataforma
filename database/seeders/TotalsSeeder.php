<?php

namespace Database\Seeders;

use App\Models\DailyTotal;
use App\Models\Service;
use App\Models\User;
use App\Support\BusinessDay;
use App\Support\Mulberry32;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Totales diarios demo: por cada día del rango, cada operador de ingreso y
 * cada servicio INGRESO activo, cantidad pseudoaleatoria determinista
 * (pesos decrecientes por posición, demanda diaria 70%-130%).
 */
class TotalsSeeder extends Seeder
{
    public function __construct(
        private ?string $month = null,
        private ?string $from = null,
        private ?string $to = null,
    ) {}

    public function run(): void
    {
        [$start, $end] = $this->bounds();

        $users = User::where('role', 'OPERATOR_INGRESO')->get();
        if ($users->isEmpty()) {
            $users = User::whereIn('username', ['ing1', 'ing2', 'ing3', 'ing4', 'ing5'])->get();
        }
        if ($users->isEmpty()) {
            return;
        }

        $services = Service::where('type', Service::TYPE_INGRESO)->where('isActive', true)
            ->orderBy('sortOrder')->orderBy('name')->get();
        if ($services->isEmpty()) {
            return;
        }

        $seed = $this->month
            ? ((int) substr($this->month, 0, 4)) * 100 + (int) substr($this->month, 5, 2)
            : 202609;
        $rng = new Mulberry32($seed);

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $day = BusinessDay::parseUtcDay($cursor->toDateString());
            $factor = 0.7 + $rng->next() * 0.6;
            foreach ($users as $user) {
                foreach ($services as $i => $service) {
                    $weight = max(1, (int) round(220 / ($i + 1)));
                    $qty = (int) round($weight * $factor / $users->count() * (0.8 + $rng->next() * 0.4));
                    if ($qty <= 0) {
                        continue;
                    }
                    DailyTotal::updateOrCreate(
                        ['userId' => $user->id, 'serviceId' => $service->id, 'date' => $day],
                        ['quantity' => $qty]
                    );
                }
            }
            $cursor->addDay();
        }
    }

    /** @return array{Carbon, Carbon} */
    private function bounds(): array
    {
        if ($this->from) {
            $start = Carbon::createFromFormat('Y-m-d', $this->from, 'UTC')->startOfDay();
            $end = $this->to
                ? Carbon::createFromFormat('Y-m-d', $this->to, 'UTC')->startOfDay()
                : Carbon::now('UTC')->startOfDay();
        } elseif ($this->month) {
            $start = Carbon::createFromFormat('Y-m', $this->month, 'UTC')->startOfMonth();
            $end = $start->copy()->endOfMonth()->startOfDay();
            $today = Carbon::now('UTC')->startOfDay();
            if ($end->gt($today)) {
                $end = $today;
            }
        } else {
            $start = Carbon::now('UTC')->startOfMonth();
            $end = Carbon::now('UTC')->startOfDay();
        }

        return [$start, $end];
    }
}
