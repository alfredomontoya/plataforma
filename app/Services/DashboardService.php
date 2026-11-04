<?php

namespace App\Services;

use App\Models\Service;
use App\Models\TramiteRegistro;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Agregados de dashboard (docs 03 § Dashboard): día/semana/rango + periodo previo + trend%.
 * Fuente: `tramite_registros` activos (un registro = un trámite).
 * Día = [D, D+1) UTC · semana = 7 días lun–dom · rango = [from, to] inclusivo (máx 93 días).
 */
class DashboardService
{
    public const RANGE_MAX_DAYS = 93;

    public function day(string $date): array
    {
        $start = BusinessDay::parseUtcDay($date);
        $prevStart = $start->copy()->subDay();

        return [
            'date' => $date,
            'ingreso' => $this->byService($start, $start->copy()->addDay(), Service::TYPE_INGRESO),
            'entrega' => $this->byService($start, $start->copy()->addDay(), Service::TYPE_ENTREGA),
            'ingresoPrevious' => $this->byService($prevStart, $start, Service::TYPE_INGRESO),
            'entregaPrevious' => $this->byService($prevStart, $start, Service::TYPE_ENTREGA),
            'totalIngreso' => $this->total($start, $start->copy()->addDay(), Service::TYPE_INGRESO),
            'totalEntrega' => $this->total($start, $start->copy()->addDay(), Service::TYPE_ENTREGA),
            'operators' => $this->operators($start, $start->copy()->addDay(), false),
        ];
    }

    public function week(string $weekStart): array
    {
        $start = BusinessDay::weekStart(BusinessDay::parseUtcDay($weekStart));
        $end = $start->copy()->addDays(7);
        $prevStart = $start->copy()->subDays(7);

        return $this->rangePayload($start, $end, $prevStart);
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

        return $this->rangePayload($start, $end, $start->copy()->subDays($days));
    }

    /** Totales por día en [from, to] inclusivo: [{date, ingreso, entrega}] (días sin datos en 0). */
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
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy(DB::raw('DATE(tramite_registros.date)'))
            ->get([
                DB::raw('DATE(tramite_registros.date) as day'),
                DB::raw("SUM(CASE WHEN services.type = 'INGRESO' THEN 1 ELSE 0 END) as ingreso"),
                DB::raw("SUM(CASE WHEN services.type = 'ENTREGA' THEN 1 ELSE 0 END) as entrega"),
            ])
            ->keyBy('day');

        $out = [];
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $key = $cursor->toDateString();
            $r = $byDay->get($key);
            $out[] = ['date' => $key, 'ingreso' => (int) ($r->ingreso ?? 0), 'entrega' => (int) ($r->entrega ?? 0)];
            $cursor->addDay();
        }

        return $out;
    }

    private function rangePayload(Carbon $start, Carbon $end, Carbon $prevStart): array
    {
        $ing = $this->total($start, $end, Service::TYPE_INGRESO);
        $ent = $this->total($start, $end, Service::TYPE_ENTREGA);
        $ingPrev = $this->total($prevStart, $start, Service::TYPE_INGRESO);
        $entPrev = $this->total($prevStart, $start, Service::TYPE_ENTREGA);

        return [
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->copy()->subDay()->toDateString(),
            'ingreso' => ['current' => $ing, 'previous' => $ingPrev, 'trend' => BusinessDay::trend($ing, $ingPrev), 'total' => $ing],
            'entrega' => ['current' => $ent, 'previous' => $entPrev, 'trend' => BusinessDay::trend($ent, $entPrev), 'total' => $ent],
            'ingresoByService' => $this->byService($start, $end, Service::TYPE_INGRESO),
            'entregaByService' => $this->byService($start, $end, Service::TYPE_ENTREGA),
            'totalIngreso' => $ing,
            'totalEntrega' => $ent,
            'operators' => $this->operators($start, $end, true),
        ];
    }

    /** Totales por servicio en un rango arbitrario (para informes semanales). */
    public function serviceTotals(Carbon $start, Carbon $end, string $type): array
    {
        return $this->byService($start, $end, $type);
    }

    /** Totales por servicio: [{serviceId, serviceName, codigo, abreviation, total}]. */
    private function byService(Carbon $start, Carbon $end, string $type): array
    {
        // (int): MySQL devuelve COUNT() como string y el frontend suma los totales.
        return TramiteRegistro::query()
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->where('services.type', $type)
            ->groupBy('tramite_registros.serviceId', 'services.name', 'services.codigo', 'services.abreviation')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get([
                'tramite_registros.serviceId as serviceId',
                'services.name as serviceName',
                'services.codigo as codigo',
                'services.abreviation as abreviation',
                DB::raw('COUNT(*) as total'),
            ])
            ->map(fn ($r) => [
                'serviceId' => $r->serviceId,
                'serviceName' => $r->serviceName,
                'codigo' => $r->codigo,
                'abreviation' => $r->abreviation,
                'total' => (int) $r->total,
            ])
            ->toArray();
    }

    private function total(Carbon $start, Carbon $end, string $type): int
    {
        return (int) TramiteRegistro::query()
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->where('services.type', $type)
            ->count();
    }

    private function operators(Carbon $start, Carbon $end, bool $sortDesc): array
    {
        $rows = TramiteRegistro::query()
            ->join('users', 'users.id', '=', 'tramite_registros.userId')
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy('tramite_registros.userId', 'users.username', 'users.firstName', 'users.lastName',
                'users.paternalSurname', 'users.maternalSurname', 'users.role')
            ->orderByDesc(DB::raw("SUM(CASE WHEN services.type = 'INGRESO' THEN 1 ELSE 0 END) + SUM(CASE WHEN services.type = 'ENTREGA' THEN 1 ELSE 0 END)"))
            ->get([
                'tramite_registros.userId as userId', 'users.username', 'users.firstName', 'users.lastName',
                'users.paternalSurname', 'users.maternalSurname', 'users.role',
                DB::raw("SUM(CASE WHEN services.type = 'INGRESO' THEN 1 ELSE 0 END) as ingreso"),
                DB::raw("SUM(CASE WHEN services.type = 'ENTREGA' THEN 1 ELSE 0 END) as entrega"),
                DB::raw("SUM(CASE WHEN services.type = 'INGRESO' THEN 1 ELSE 0 END) + SUM(CASE WHEN services.type = 'ENTREGA' THEN 1 ELSE 0 END) as total"),
            ]);

        $out = $rows->map(fn ($r) => [
            'userId' => $r->userId,
            'username' => $r->username,
            'fullName' => trim("{$r->firstName} {$r->lastName} {$r->paternalSurname} {$r->maternalSurname}"),
            'role' => $r->role,
            'ingreso' => (int) $r->ingreso,
            'entrega' => (int) $r->entrega,
            'total' => (int) $r->total,
        ]);

        return $sortDesc ? $out->values()->toArray() : $out->values()->toArray();
    }
}
