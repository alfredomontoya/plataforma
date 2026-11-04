<?php

namespace App\Services;

use App\Models\Service;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Agregados de trámites para el dashboard (día/semana/rango + serie diaria),
 * calculados desde `tramite_registros` (solo activos, tipo INGRESO).
 * Día = [D, D+1) UTC · semana = 7 días lun–dom · rango máx 93 días.
 */
class TotalService
{
    public const RANGE_MAX_DAYS = 93;

    public function day(string $date): array
    {
        $start = BusinessDay::parseUtcDay($date);

        return [
            'date' => $date,
            'total' => $this->total($start, $start->copy()->addDay()),
            'byTramite' => $this->byTramite($start, $start->copy()->addDay()),
            'operators' => $this->activeOperators(),
        ];
    }

    public function week(string $weekStart): array
    {
        $start = BusinessDay::weekStart(BusinessDay::parseUtcDay($weekStart));
        $end = $start->copy()->addDays(7);

        return $this->rangePayload($start, $end);
    }

    public function range(string $from, string $to): array
    {
        $start = BusinessDay::parseUtcDay($from);
        $end = BusinessDay::parseUtcDay($to)->addDay(); // inclusivo
        $days = (int) $start->diffInDays($end);

        if ($days > self::RANGE_MAX_DAYS) {
            throw new HttpResponseException(
                ApiResponse::error('El rango no puede superar 93 días.', 400)
            );
        }

        return $this->rangePayload($start, $end);
    }

    /** Totales por día en [from, to] inclusivo: [{date, total}] (días sin datos en 0). */
    public function daily(string $from, string $to): array
    {
        $start = BusinessDay::parseUtcDay($from);
        $end = BusinessDay::parseUtcDay($to)->addDay(); // inclusivo
        $days = (int) $start->diffInDays($end);

        if ($days > self::RANGE_MAX_DAYS) {
            throw new HttpResponseException(
                ApiResponse::error('El rango no puede superar 93 días.', 400)
            );
        }

        $byDay = TramiteRegistro::query()
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('services.type', Service::TYPE_INGRESO)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy(DB::raw('DATE(tramite_registros.date)'))
            ->get([
                DB::raw('DATE(tramite_registros.date) as day'),
                DB::raw('COUNT(*) as total'),
            ])
            ->keyBy('day');

        $out = [];
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $key = $cursor->toDateString();
            $r = $byDay->get($key);
            $out[] = ['date' => $key, 'total' => (int) ($r->total ?? 0)];
            $cursor->addDay();
        }

        return $out;
    }

    private function rangePayload(Carbon $start, Carbon $end): array
    {
        return [
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->copy()->subDay()->toDateString(),
            'total' => $this->total($start, $end),
            'byTramite' => $this->byTramite($start, $end),
            'operators' => $this->activeOperators(),
        ];
    }

    /** Operadores registrados con estado activo (no depende del periodo). */
    private function activeOperators(): int
    {
        return (int) User::whereIn('role', ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA'])
            ->where('isActive', true)
            ->count();
    }

    private function total(Carbon $start, Carbon $end): int
    {
        return (int) TramiteRegistro::query()
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('services.type', Service::TYPE_INGRESO)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->count();
    }

    /** Totales por servicio en orden del catálogo (solo > 0): [{nro, serviceId, tramite, total}]. */
    private function byTramite(Carbon $start, Carbon $end): array
    {
        $sums = TramiteRegistro::query()
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('services.type', Service::TYPE_INGRESO)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy('tramite_registros.serviceId', 'services.name', 'services.abreviation', 'services.codigo', 'services.sortOrder')
            ->orderBy('services.sortOrder')
            ->orderBy('services.name')
            ->get([
                'tramite_registros.serviceId as serviceId',
                'services.name as serviceName',
                'services.abreviation as abreviation',
                'services.codigo as codigo',
                DB::raw('COUNT(*) as total'),
            ]);

        $out = [];
        foreach ($sums->values() as $i => $row) {
            $total = (int) $row->total;
            if ($total > 0) {
                $out[] = [
                    'nro' => $i + 1,
                    'serviceId' => $row->serviceId,
                    'tramite' => $row->serviceName,
                    'abreviation' => $row->abreviation,
                    'codigo' => $row->codigo,
                    'total' => $total,
                ];
            }
        }

        return $out;
    }
}
