<?php

namespace App\Services;

use App\Models\Service;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Registro individual de trámites: un registro = un trámite.
 * Reglas de rol por tipo (INGRESO/ENTREGA), fechas (presente/pasado) y
 * edición (dueño o ADMIN).
 */
class TramiteRegistroService
{
    public function assertRoleForType(User $user, string $type): void
    {
        $map = [
            'OPERATOR_INGRESO' => Service::TYPE_INGRESO,
            'OPERATOR_ENTREGA' => Service::TYPE_ENTREGA,
        ];

        if ($user->role === 'ADMIN') {
            return;
        }
        if (($map[$user->role] ?? null) !== $type) {
            throw new HttpResponseException(
                ApiResponse::error('Su rol no puede registrar este tipo de trámite.', 403)
            );
        }
    }

    /**
     * Sin fechas futuras. Las pasadas requieren `canBackfill` (activo por
     * defecto; un ADMIN puede desactivarlo por usuario).
     */
    public function assertDateAllowed(User $user, Carbon $dayUtc): void
    {
        $today = BusinessDay::todayUtc();

        if ($dayUtc->gt($today)) {
            throw new HttpResponseException(
                ApiResponse::error('No se pueden registrar fechas futuras.', 403)
            );
        }

        if ($dayUtc->gte($today) || $user->canBackfill) {
            return;
        }

        throw new HttpResponseException(
            ApiResponse::error('No tiene permiso para registrar fechas pasadas.', 403)
        );
    }

    /** Servicios activos que puede usar el usuario según su rol. */
    public function catalog(User $user): array
    {
        $q = Service::where('isActive', true);

        if ($user->role !== 'ADMIN') {
            $q->where('type', $user->role === 'OPERATOR_ENTREGA'
                ? Service::TYPE_ENTREGA
                : Service::TYPE_INGRESO);
        }

        return $q->orderByDesc('type')->orderBy('sortOrder')->orderBy('name')
            ->get(['id', 'name', 'codigo', 'abreviation', 'type'])
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'codigo' => $s->codigo,
                'abreviation' => $s->abreviation,
                'type' => $s->type,
            ])
            ->toArray();
    }

    /** Registros del día: propios para operadores, todos para ADMIN. */
    public function forDate(User $user, string $date, ?string $search = null): array
    {
        return $this->forRange($user, $date, $date, $search);
    }

    /**
     * Registros de un rango inclusivo [from, to]: propios para operadores,
     * todos para ADMIN. `search` filtra por trámite, placa/póliza,
     * observaciones o usuario.
     */
    public function forRange(User $user, string $from, string $to, ?string $search = null): array
    {
        return $this->rangeQuery($user, $from, $to, $search)->get()->toArray();
    }

    /** Página de registros del rango, con el mismo alcance y búsqueda. */
    public function paginateRange(
        User $user,
        string $from,
        string $to,
        ?string $search,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        return $this->rangeQuery($user, $from, $to, $search)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** Cantidad de registros activos del rango (mismos filtros y alcance). */
    public function activeCount(User $user, string $from, string $to, ?string $search = null): int
    {
        return $this->rangeQuery($user, $from, $to, $search)
            ->reorder()
            ->where('activo', true)
            ->count();
    }

    /**
     * Totales por tipo de trámite (solo activos), con el alcance por rol y
     * los filtros actuales. Ordenado de mayor a menor cantidad.
     */
    public function summaryByType(User $user, string $from, string $to, ?string $search = null): array
    {
        return $this->rangeQuery($user, $from, $to, $search)
            ->reorder()
            ->where('activo', true)
            ->select('serviceId')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('serviceId')
            ->orderByDesc('total')
            ->get()
            ->map(fn (TramiteRegistro $row) => [
                'serviceId' => $row->serviceId,
                'name' => $row->service?->name ?? 'Sin tipo',
                'codigo' => $row->service?->codigo,
                'abreviation' => $row->service?->abreviation,
                'type' => $row->service?->type,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /** Consulta base compartida por listado, paginación y exportaciones. */
    private function rangeQuery(User $user, string $from, string $to, ?string $search = null): Builder
    {
        $start = BusinessDay::parseUtcDay($from);
        $end = BusinessDay::parseUtcDay($to)->addDay();

        $q = TramiteRegistro::with([
            'service:id,name,codigo,abreviation,type',
            'user:id,username,firstName,lastName,paternalSurname,maternalSurname',
        ])
            ->where('date', '>=', $start)
            ->where('date', '<', $end)
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        if ($user->role !== 'ADMIN') {
            $q->where('userId', $user->id);
        }

        $this->applySearch($q, $search);

        return $q;
    }

    /** Cada palabra debe coincidir con al menos uno de los campos buscables. */
    private function applySearch($query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $words = array_slice(
            preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            0,
            10,
        );

        foreach ($words as $word) {
            $word = str_replace(['%', '_'], '', $word);
            if ($word === '') {
                continue;
            }
            $like = '%'.$word.'%';

            $query->where(function ($q) use ($like) {
                $q->where('placa', 'like', $like)
                    ->orWhere('observaciones', 'like', $like)
                    ->orWhereHas('service', function ($s) use ($like) {
                        $s->where('name', 'like', $like)
                            ->orWhere('codigo', 'like', $like)
                            ->orWhere('abreviation', 'like', $like)
                            ->orWhere('type', 'like', $like);
                    })
                    ->orWhereHas('user', function ($u) use ($like) {
                        $u->where('username', 'like', $like)
                            ->orWhere('firstName', 'like', $like)
                            ->orWhere('lastName', 'like', $like)
                            ->orWhere('paternalSurname', 'like', $like)
                            ->orWhere('maternalSurname', 'like', $like);
                    });
            });
        }
    }

    public function create(User $user, array $data): TramiteRegistro
    {
        $service = $this->assertServiceAllowed($user, $data['serviceId']);
        $this->assertRoleForType($user, $service->type);

        $day = BusinessDay::parseUtcDay($data['date']);
        $this->assertDateAllowed($user, $day);

        return TramiteRegistro::create([
            'userId' => $user->id,
            'serviceId' => $service->id,
            'date' => $day,
            'placa' => $this->normalizePlaca($data['placa']),
            'observaciones' => $data['observaciones'] ?? null,
            'activo' => $data['activo'] ?? true,
        ])->fresh(['service', 'user']);
    }

    public function update(User $user, TramiteRegistro $registro, array $data): TramiteRegistro
    {
        if ($user->role !== 'ADMIN' && $registro->userId !== $user->id) {
            throw new HttpResponseException(
                ApiResponse::error('No puede editar un registro de otro usuario.', 403)
            );
        }

        if (isset($data['serviceId'])) {
            $service = $this->assertServiceAllowed($user, $data['serviceId']);
            $this->assertRoleForType($user, $service->type);
            $registro->serviceId = $service->id;
        }

        if (isset($data['date'])) {
            $day = BusinessDay::parseUtcDay($data['date']);
            $this->assertDateAllowed($user, $day);
            $registro->date = $day;
        }

        if (array_key_exists('placa', $data)) {
            $registro->placa = $this->normalizePlaca($data['placa']);
        }
        if (array_key_exists('observaciones', $data)) {
            $registro->observaciones = $data['observaciones'];
        }
        if (array_key_exists('activo', $data)) {
            $registro->activo = (bool) $data['activo'];
        }

        $registro->save();

        return $registro->fresh(['service', 'user']);
    }

    /**
     * Eliminación lógica: el registro queda inactivo y deja de contabilizarse.
     * Solo el dueño o un ADMIN pueden hacerlo.
     */
    public function deactivate(User $user, TramiteRegistro $registro): TramiteRegistro
    {
        if ($user->role !== 'ADMIN' && $registro->userId !== $user->id) {
            throw new HttpResponseException(
                ApiResponse::error('No puede eliminar un registro de otro usuario.', 403)
            );
        }

        $registro->activo = false;
        $registro->save();

        return $registro->fresh(['service', 'user']);
    }

    private function assertServiceAllowed(User $user, string $id): Service
    {
        $service = Service::where('isActive', true)->find($id);

        if (! $service) {
            throw new HttpResponseException(
                ApiResponse::error('El tipo de trámite no existe o no está activo.', 400)
            );
        }

        if ($user->role !== 'ADMIN') {
            $expected = $user->role === 'OPERATOR_ENTREGA'
                ? Service::TYPE_ENTREGA
                : Service::TYPE_INGRESO;

            if ($service->type !== $expected) {
                throw new HttpResponseException(
                    ApiResponse::error('Su rol no puede registrar este tipo de trámite.', 403)
                );
            }
        }

        return $service;
    }

    /** Alfanumérico sin espacios, en mayúsculas. */
    private function normalizePlaca(string $placa): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', trim($placa)));
    }
}
