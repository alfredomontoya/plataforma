<?php

namespace App\Services;

use App\Models\TipoRegistro;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Agregados de trámites para el dashboard (día/semana/rango + serie diaria),
 * calculados desde `tramite_registros` (solo activos).
 * El bloque principal cuenta INGRESO; CIERRE se expone por separado.
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
            'totalEntrega' => $this->total($start, $start->copy()->addDay(), TipoRegistro::CODE_ENTREGA),
            'totalCierre' => $this->total($start, $start->copy()->addDay(), TipoRegistro::CODE_CIERRE),
            'byTramite' => $this->byTramite($start, $start->copy()->addDay()),
            'byTramiteEntrega' => $this->byTramite($start, $start->copy()->addDay(), TipoRegistro::CODE_ENTREGA),
            'byTramiteCierre' => $this->byTramite($start, $start->copy()->addDay(), TipoRegistro::CODE_CIERRE),
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

    /** Totales por día en [from, to] inclusivo: [{date, total, entrega, cierre}] (días sin datos en 0). */
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
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->where('tramite_registros.activo', true)
            ->whereIn('tipo_registros.codigo', [
                TipoRegistro::CODE_INGRESO,
                TipoRegistro::CODE_ENTREGA,
                TipoRegistro::CODE_CIERRE,
            ])
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy(DB::raw('DATE(tramite_registros.date)'))
            ->get([
                DB::raw('DATE(tramite_registros.date) as day'),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'INGRESO' THEN 1 ELSE 0 END) as total"),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'ENTREGA' THEN 1 ELSE 0 END) as entrega"),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'CIERRE' THEN 1 ELSE 0 END) as cierre"),
            ])
            ->keyBy('day');

        $out = [];
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $key = $cursor->toDateString();
            $r = $byDay->get($key);
            $out[] = [
                'date' => $key,
                'total' => (int) ($r->total ?? 0),
                'entrega' => (int) ($r->entrega ?? 0),
                'cierre' => (int) ($r->cierre ?? 0),
            ];
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
            'totalEntrega' => $this->total($start, $end, TipoRegistro::CODE_ENTREGA),
            'totalCierre' => $this->total($start, $end, TipoRegistro::CODE_CIERRE),
            'byTramite' => $this->byTramite($start, $end),
            'byTramiteEntrega' => $this->byTramite($start, $end, TipoRegistro::CODE_ENTREGA),
            'byTramiteCierre' => $this->byTramite($start, $end, TipoRegistro::CODE_CIERRE),
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

    private function total(Carbon $start, Carbon $end, string $type = TipoRegistro::CODE_INGRESO): int
    {
        return (int) TramiteRegistro::query()
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->where('tramite_registros.activo', true)
            ->where('tipo_registros.codigo', $type)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->count();
    }

    /** Totales por servicio en orden del catálogo (solo > 0): [{nro, serviceId, tramite, total}]. */
    private function byTramite(Carbon $start, Carbon $end, string $type = TipoRegistro::CODE_INGRESO): array
    {
        $sums = TramiteRegistro::query()
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('tipo_registros.codigo', $type)
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
