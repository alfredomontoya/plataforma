<?php

namespace App\Services;

use App\Models\TipoRegistro;
use App\Models\TramiteRegistro;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Agregados de dashboard (docs 03 § Dashboard): día/semana/rango + periodo previo + trend%.
 * Fuente: `tramite_registros` activos (un registro = un trámite).
 * El tipo (INGRESO/ENTREGA/CIERRE) se toma de `tipo_registros`, no de `services.type`,
 * porque el catálogo de trámites es único para todos los tipos.
 * Día = [D, D+1) UTC · semana = 7 días lun–dom · rango = [from, to] inclusivo (máx 93 días).
 */
class DashboardService
{
    public const RANGE_MAX_DAYS = 93;

    public function day(string $date): array
    {
        $start = BusinessDay::parseUtcDay($date);
        $prevStart = $start->copy()->subDay();
        $end = $start->copy()->addDay();

        return [
            'date' => $date,
            'ingreso' => $this->byService($start, $end, TipoRegistro::CODE_INGRESO),
            'entrega' => $this->byService($start, $end, TipoRegistro::CODE_ENTREGA),
            'cierre' => $this->byService($start, $end, TipoRegistro::CODE_CIERRE),
            'ingresoPrevious' => $this->byService($prevStart, $start, TipoRegistro::CODE_INGRESO),
            'entregaPrevious' => $this->byService($prevStart, $start, TipoRegistro::CODE_ENTREGA),
            'cierrePrevious' => $this->byService($prevStart, $start, TipoRegistro::CODE_CIERRE),
            'totalIngreso' => $this->total($start, $end, TipoRegistro::CODE_INGRESO),
            'totalEntrega' => $this->total($start, $end, TipoRegistro::CODE_ENTREGA),
            'totalCierre' => $this->total($start, $end, TipoRegistro::CODE_CIERRE),
            'entregaItems' => $this->entregaItems($start, $end),
            'operators' => $this->operators($start, $end, false),
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

    /** Totales por día en [from, to] inclusivo: [{date, ingreso, entrega, cierre}] (días sin datos en 0). */
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
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy(DB::raw('DATE(tramite_registros.date)'))
            ->get([
                DB::raw('DATE(tramite_registros.date) as day'),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'INGRESO' THEN 1 ELSE 0 END) as ingreso"),
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
                'ingreso' => (int) ($r->ingreso ?? 0),
                'entrega' => (int) ($r->entrega ?? 0),
                'cierre' => (int) ($r->cierre ?? 0),
            ];
            $cursor->addDay();
        }

        return $out;
    }

    private function rangePayload(Carbon $start, Carbon $end, Carbon $prevStart): array
    {
        $ing = $this->total($start, $end, TipoRegistro::CODE_INGRESO);
        $ent = $this->total($start, $end, TipoRegistro::CODE_ENTREGA);
        $cie = $this->total($start, $end, TipoRegistro::CODE_CIERRE);
        $ingPrev = $this->total($prevStart, $start, TipoRegistro::CODE_INGRESO);
        $entPrev = $this->total($prevStart, $start, TipoRegistro::CODE_ENTREGA);
        $ciePrev = $this->total($prevStart, $start, TipoRegistro::CODE_CIERRE);

        return [
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->copy()->subDay()->toDateString(),
            'ingreso' => ['current' => $ing, 'previous' => $ingPrev, 'trend' => BusinessDay::trend($ing, $ingPrev), 'total' => $ing],
            'entrega' => ['current' => $ent, 'previous' => $entPrev, 'trend' => BusinessDay::trend($ent, $entPrev), 'total' => $ent],
            'cierre' => ['current' => $cie, 'previous' => $ciePrev, 'trend' => BusinessDay::trend($cie, $ciePrev), 'total' => $cie],
            'ingresoByService' => $this->byService($start, $end, TipoRegistro::CODE_INGRESO),
            'entregaByService' => $this->byService($start, $end, TipoRegistro::CODE_ENTREGA),
            'cierreByService' => $this->byService($start, $end, TipoRegistro::CODE_CIERRE),
            'totalIngreso' => $ing,
            'totalEntrega' => $ent,
            'totalCierre' => $cie,
            'entregaItems' => $this->entregaItems($start, $end),
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
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->join('services', 'services.id', '=', 'tramite_registros.serviceId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->where('tipo_registros.codigo', $type)
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

    /**
     * Conteo de ítems entregados en el periodo.
     *
     * @return array<int, array{itemId: string, name: string, codigo: string, total: int}>
     */
    private function entregaItems(Carbon $start, Carbon $end): array
    {
        return TramiteRegistro::query()
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->join('tramite_registro_entrega_items as tri', 'tri.tramiteRegistroId', '=', 'tramite_registros.id')
            ->join('entrega_items', 'entrega_items.id', '=', 'tri.entregaItemId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->where('tipo_registros.requiereItems', true)
            ->groupBy('entrega_items.id', 'entrega_items.name', 'entrega_items.codigo', 'entrega_items.sortOrder')
            ->orderBy('entrega_items.sortOrder')
            ->orderBy('entrega_items.name')
            ->get([
                'entrega_items.id as itemId',
                'entrega_items.name as name',
                'entrega_items.codigo as codigo',
                DB::raw('COUNT(*) as total'),
            ])
            ->map(fn ($r) => [
                'itemId' => $r->itemId,
                'name' => $r->name,
                'codigo' => $r->codigo,
                'total' => (int) $r->total,
            ])
            ->toArray();
    }

    private function total(Carbon $start, Carbon $end, string $type): int
    {
        return (int) TramiteRegistro::query()
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->where('tipo_registros.codigo', $type)
            ->count();
    }

    private function operators(Carbon $start, Carbon $end, bool $sortDesc): array
    {
        $rows = TramiteRegistro::query()
            ->join('users', 'users.id', '=', 'tramite_registros.userId')
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->where('tramite_registros.activo', true)
            ->where('tramite_registros.date', '>=', $start)
            ->where('tramite_registros.date', '<', $end)
            ->groupBy('tramite_registros.userId', 'users.username', 'users.firstName', 'users.lastName',
                'users.paternalSurname', 'users.maternalSurname', 'users.role')
            ->orderByDesc(DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'INGRESO' THEN 1 ELSE 0 END) + SUM(CASE WHEN tipo_registros.codigo = 'ENTREGA' THEN 1 ELSE 0 END) + SUM(CASE WHEN tipo_registros.codigo = 'CIERRE' THEN 1 ELSE 0 END)"))
            ->get([
                'tramite_registros.userId as userId', 'users.username', 'users.firstName', 'users.lastName',
                'users.paternalSurname', 'users.maternalSurname', 'users.role',
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'INGRESO' THEN 1 ELSE 0 END) as ingreso"),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'ENTREGA' THEN 1 ELSE 0 END) as entrega"),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'CIERRE' THEN 1 ELSE 0 END) as cierre"),
                DB::raw("SUM(CASE WHEN tipo_registros.codigo = 'INGRESO' THEN 1 ELSE 0 END) + SUM(CASE WHEN tipo_registros.codigo = 'ENTREGA' THEN 1 ELSE 0 END) + SUM(CASE WHEN tipo_registros.codigo = 'CIERRE' THEN 1 ELSE 0 END) as total"),
            ]);

        $out = $rows->map(fn ($r) => [
            'userId' => $r->userId,
            'username' => $r->username,
            'fullName' => trim("{$r->firstName} {$r->lastName} {$r->paternalSurname} {$r->maternalSurname}"),
            'role' => $r->role,
            'ingreso' => (int) $r->ingreso,
            'entrega' => (int) $r->entrega,
            'cierre' => (int) $r->cierre,
            'total' => (int) $r->total,
        ]);

        return $sortDesc ? $out->values()->toArray() : $out->values()->toArray();
    }
}
