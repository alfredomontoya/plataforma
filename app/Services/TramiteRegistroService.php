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

class TramiteRegistroService
{
    private const ROLES_TICKET_REQUIRED = [
        'OPERATOR_INGRESO',
        'OPERATOR_ENTREGA',
    ];

    private const ESTADOS_VALIDOS = [
        'procesado',
        'recepcionado',
    ];

    public function assertRoleForType(User $user, string $type): void
    {
        $map = [
            'OPERATOR_INGRESO' => Service::TYPE_INGRESO,
            'OPERATOR_ENTREGA' => Service::TYPE_ENTREGA,
        ];

        if (in_array($user->role, ['ADMIN', 'JEFE'], true)) {
            return;
        }

        if (($map[$user->role] ?? null) !== $type) {
            throw new HttpResponseException(
                ApiResponse::error('Su rol no puede registrar este tipo de trámite.', 403)
            );
        }
    }

    /**
     * El ticket es obligatorio para operadores de ingreso y entrega.
     */
    private function assertTicketRequired(User $user, ?string $ticket): void
    {
        if (
            in_array($user->role, self::ROLES_TICKET_REQUIRED, true)
            && ($ticket === null || trim($ticket) === '')
        ) {
            throw new HttpResponseException(
                ApiResponse::error('El número de ticket es obligatorio para su rol.', 422)
            );
        }
    }

    /**
     * Comprueba si existe otro registro con el mismo ticket.
     * No bloquea la operación.
     */
    private function ticketWarning(?string $ticket, ?string $ignoreId = null): ?string
    {
        if ($ticket === null || trim($ticket) === '') {
            return null;
        }

        $query = TramiteRegistro::where('ticket', trim($ticket));

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            return 'Advertencia: este número de ticket ya está registrado.';
        }

        return null;
    }

    /**
     * Agrega una advertencia temporal al modelo.
     * No se almacena en la base de datos.
     */
    private function attachTicketWarning(
        TramiteRegistro $registro,
        ?string $warning
    ): TramiteRegistro {
        $registro->setAttribute('advertencia', $warning);

        return $registro;
    }

    /**
     * Sin fechas futuras. Las pasadas requieren canBackfill.
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

        if (!in_array($user->role, ['ADMIN', 'JEFE'], true)) {
            $q->where(
                'type',
                $user->role === 'OPERATOR_ENTREGA'
                    ? Service::TYPE_ENTREGA
                    : Service::TYPE_INGRESO
            );
        }

        return $q->orderByDesc('type')
            ->orderBy('sortOrder')
            ->orderBy('name')
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

    /** Registros del día. */
    public function forDate(
        User $user,
        string $date,
        ?string $search = null
    ): array {
        return $this->forRange($user, $date, $date, $search);
    }

    /** Registros de un rango inclusivo. */
    public function forRange(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): array {
        return $this->rangeQuery($user, $from, $to, $search)
            ->get()
            ->toArray();
    }

    /** Paginación de registros. */
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

    /** Cantidad de registros activos. */
    public function activeCount(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): int {
        return $this->rangeQuery($user, $from, $to, $search)
            ->reorder()
            ->where('activo', true)
            ->count();
    }

    /** Totales por tipo de trámite. */
    public function summaryByType(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): array {
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

    /** Consulta base compartida por listados y exportaciones. */
    private function rangeQuery(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): Builder {
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

        if (!in_array($user->role, ['ADMIN', 'JEFE'], true)) {
            $q->where('userId', $user->id);
        }

        $this->applySearch($q, $search);

        return $q;
    }

    /** Cada palabra debe coincidir con al menos un campo buscable. */
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
                    ->orWhere('ticket', 'like', $like)
                    ->orWhere('estado', 'like', $like)
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

    /** Crear un registro. */
    public function create(User $user, array $data): TramiteRegistro
    {
        $service = $this->assertServiceAllowed($user, $data['serviceId']);

        $this->assertRoleForType($user, $service->type);

        $ticket = $this->normalizeTicket($data['ticket'] ?? null);

        $this->assertTicketRequired($user, $ticket);

        $estado = $data['estado'] ?? 'procesado';

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw new HttpResponseException(
                ApiResponse::error('El estado debe ser procesado o recepcionado.', 422)
            );
        }

        $day = BusinessDay::parseUtcDay($data['date']);

        $this->assertDateAllowed($user, $day);

        // La duplicidad solamente genera una advertencia.
        $warning = $this->ticketWarning($ticket);

        $registro = TramiteRegistro::create([
            'userId' => $user->id,
            'serviceId' => $service->id,
            'date' => $day,
            'placa' => $this->normalizePlaca($data['placa']),
            'observaciones' => $data['observaciones'] ?? null,
            'activo' => $data['activo'] ?? true,
            'ticket' => $ticket,
            'estado' => $estado,
        ])->fresh(['service', 'user']);

        return $this->attachTicketWarning($registro, $warning);
    }

    /** Actualizar un registro. */
    public function update(
        User $user,
        TramiteRegistro $registro,
        array $data
    ): TramiteRegistro {
        if (
            !in_array($user->role, ['ADMIN', 'JEFE'], true)
            && $registro->userId !== $user->id
        ) {
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

        if (array_key_exists('ticket', $data)) {
            $registro->ticket = $this->normalizeTicket($data['ticket']);
        }

        // Para operadores, se exige que el ticket final no esté vacío.
        $this->assertTicketRequired(
            $user,
            $registro->ticket
        );

        if (array_key_exists('estado', $data)) {
            if (!in_array($data['estado'], self::ESTADOS_VALIDOS, true)) {
                throw new HttpResponseException(
                    ApiResponse::error('El estado debe ser procesado o recepcionado.', 422)
                );
            }

            $registro->estado = $data['estado'];
        }

        // Excluir el propio registro al comprobar duplicidad.
        $warning = $this->ticketWarning(
            $registro->ticket,
            (string) $registro->getKey()
        );

        $registro->save();

        $registro = $registro->fresh(['service', 'user']);

        return $this->attachTicketWarning($registro, $warning);
    }

    /**
     * Eliminación lógica.
     */
    public function deactivate(
        User $user,
        TramiteRegistro $registro
    ): TramiteRegistro {
        if (
            !in_array($user->role, ['ADMIN', 'JEFE'], true)
            && $registro->userId !== $user->id
        ) {
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

        if (!$service) {
            throw new HttpResponseException(
                ApiResponse::error('El tipo de trámite no existe o no está activo.', 400)
            );
        }

        if (!in_array($user->role, ['ADMIN', 'JEFE'], true)) {
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
        return mb_strtoupper(
            (string) preg_replace('/\s+/', '', trim($placa))
        );
    }

    /** Ticket alfanumérico sin espacios y en mayúsculas; vacío => null. */
    private function normalizeTicket(?string $ticket): ?string
    {
        if ($ticket === null) {
            return null;
        }

        $ticket = mb_strtoupper(
            (string) preg_replace('/\s+/', '', trim($ticket))
        );

        return $ticket !== '' ? $ticket : null;
    }
}
