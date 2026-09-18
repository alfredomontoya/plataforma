<?php

namespace App\Services;

use App\Models\DailyTotal;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Ingreso diario total: carga por trámite (upsert usuario×trámite×día)
 * y agregados día/semana/rango + serie diaria para el dashboard.
 * Día = [D, D+1) UTC · semana = 7 días lun–dom · rango máx 93 días.
 */
class TotalService
{
    public const RANGE_MAX_DAYS = 93;

    public function __construct(private EntryService $entries) {}

    public function assertRole(User $user): void
    {
        if (! in_array($user->role, ['ADMIN', 'OPERATOR_INGRESO'], true)) {
            throw new HttpResponseException(ApiResponse::error('Su rol no puede registrar el total diario.', 403));
        }
    }

    /** Servicios INGRESO activos ordenados (catálogo del formulario). */
    public function catalogServices()
    {
        return Service::where('type', Service::TYPE_INGRESO)->where('isActive', true)
            ->orderBy('sortOrder')->orderBy('name')->get();
    }

    /** Catálogo numerado para el formulario: [{nro, tramite, serviceId}]. */
    public function catalog(): array
    {
        return $this->catalogServices()->values()->map(
            fn ($s, $i) => ['nro' => $i + 1, 'tramite' => $s->name, 'serviceId' => $s->id]
        )->toArray();
    }

    /**
     * Resuelve nombres contra servicios INGRESO (exacto, luego insensible
     * a mayúsculas/espacios) y REGISTRA los faltantes como servicios
     * INGRESO activos. Devuelve [{name, serviceId, codigo, abreviation, created}].
     */
    public function resolveNames(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            $name = (string) preg_replace('/\s+/', ' ', trim((string) $name));
            if ($name !== '' && ! in_array($name, $clean, true)) {
                $clean[] = $name;
            }
        }
        if ($clean === []) {
            return [];
        }

        return DB::transaction(function () use ($clean) {
            $services = $this->catalogServices();
            $byExact = $services->keyBy('name');
            $byNorm = $services->keyBy(fn ($s) => mb_strtoupper((string) preg_replace('/\s+/', ' ', trim($s->name))));
            $maxSort = (int) ($services->max('sortOrder') ?? 0);
            $takenCodigos = Service::whereNotNull('codigo')->pluck('codigo')
                ->map(fn ($c) => mb_strtoupper((string) $c))->all();

            $out = [];
            foreach ($clean as $name) {
                $found = $byExact->get($name)
                    ?? $byNorm->get(mb_strtoupper((string) preg_replace('/\s+/', ' ', trim($name))));
                if ($found) {
                    $out[] = [
                        'name' => $found->name, 'serviceId' => $found->id,
                        'codigo' => $found->codigo, 'abreviation' => $found->abreviation,
                        'created' => false,
                    ];
                    continue;
                }
                $maxSort++;
                $created = Service::create([
                    'name' => $name,
                    'codigo' => $this->uniqueCodigo(self::initials($name), $takenCodigos),
                    'abreviation' => self::abbreviation($name),
                    'type' => Service::TYPE_INGRESO,
                    'isActive' => true,
                    'sortOrder' => $maxSort,
                ]);
                $byExact->put($created->name, $created);
                $byNorm->put(mb_strtoupper($created->name), $created);
                $out[] = [
                    'name' => $created->name, 'serviceId' => $created->id,
                    'codigo' => $created->codigo, 'abreviation' => $created->abreviation,
                    'created' => true,
                ];
            }

            return $out;
        });
    }

    /** Conectores que no aportan a la abreviación. */
    private const STOPWORDS = ['Y', 'E', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'CON', 'POR', 'PARA', 'EN', 'AL'];

    /** Palabras del nombre (mayúsculas, solo letras/números). */
    private static function words(string $name): array
    {
        $upper = mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $name)));
        $words = preg_split('/[^A-Z0-9Ñ]+/u', $upper, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : array_values($words);
    }

    /** Código = primera letra de cada palabra (ej. "DUPLICADO PLACA VEHICULO" → "DPV"). */
    public static function initials(string $name): string
    {
        $code = '';
        foreach (self::words($name) as $w) {
            $code .= mb_substr($w, 0, 1);
        }

        return $code !== '' ? $code : 'SVC';
    }

    /** Abreviación = 3 primeras letras de cada palabra con punto (ej. "DUP. PLA. VEH."). */
    public static function abbreviation(string $name): string
    {
        $parts = [];
        foreach (self::words($name) as $w) {
            if (in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $parts[] = mb_substr($w, 0, 3).'.';
        }

        $abbr = $parts !== [] ? implode(' ', $parts) : mb_substr(self::initials($name), 0, 3).'.';

        return mb_substr($abbr, 0, 32);
    }

    /** Código único: ante coincidencia agrega 2, 3, ... (ej. "DPV" → "DPV2"). */
    private function uniqueCodigo(string $base, array &$taken): string
    {
        $candidate = $base;
        $n = 2;
        while (in_array(mb_strtoupper($candidate), $taken, true)) {
            $candidate = $base.$n;
            $n++;
        }
        $taken[] = mb_strtoupper($candidate);

        return $candidate;
    }

    /** Crea/actualiza en lote (upsert por usuario×servicio×día). Devuelve las filas. */
    public function storeBatch(User $user, string $day, array $items): array
    {
        $this->assertRole($user);
        $dayUtc = BusinessDay::parseUtcDay($day);
        $this->entries->assertDateAllowed($user, $dayUtc);

        $services = Service::where('type', Service::TYPE_INGRESO)->where('isActive', true)
            ->whereIn('id', collect($items)->pluck('serviceId'))->get()->keyBy('id');

        foreach ($items as $item) {
            if (! $services->has($item['serviceId'])) {
                throw new HttpResponseException(
                    ApiResponse::error('Un servicio no está registrado como ingreso activo.', 400)
                );
            }
        }

        return DB::transaction(function () use ($user, $dayUtc, $items) {
            $rows = [];
            foreach ($items as $item) {
                $rows[] = DailyTotal::updateOrCreate(
                    ['userId' => $user->id, 'serviceId' => $item['serviceId'], 'date' => $dayUtc],
                    ['quantity' => $item['quantity']]
                )->fresh(['service']);
            }

            return $rows;
        });
    }

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

        $byDay = DailyTotal::query()
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->groupBy(DB::raw('DATE(date)'))
            ->get([DB::raw('DATE(date) as day'), DB::raw('SUM(quantity) as total')])
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
        return (int) DailyTotal::where('date', '>=', $start)->where('date', '<', $end)->sum('quantity');
    }

    /** Totales por servicio en orden del catálogo (solo > 0): [{nro, serviceId, tramite, total}]. */
    private function byTramite(Carbon $start, Carbon $end): array
    {
        $sums = DailyTotal::query()
            ->join('services', 'services.id', '=', 'daily_totals.serviceId')
            ->where('daily_totals.date', '>=', $start)
            ->where('daily_totals.date', '<', $end)
            ->groupBy('daily_totals.serviceId', 'services.name', 'services.abreviation', 'services.codigo', 'services.sortOrder')
            ->orderBy('services.sortOrder')
            ->orderBy('services.name')
            ->get([
                'daily_totals.serviceId as serviceId',
                'services.name as serviceName',
                'services.abreviation as abreviation',
                'services.codigo as codigo',
                DB::raw('SUM(daily_totals.quantity) as total'),
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
