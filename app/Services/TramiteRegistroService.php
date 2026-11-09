<?php

namespace App\Services;

use App\Models\EntregaItem;
use App\Models\Service;
use App\Models\TipoRegistro;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

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

    /**
     * El ticket es obligatorio para operadores cuando el tipo de registro lo exige.
     */
    private function assertTicketRequired(
        User $user,
        ?string $ticket,
        ?TipoRegistro $tipoRegistro
    ): void {
        if (
            in_array($user->role, self::ROLES_TICKET_REQUIRED, true)
            && $tipoRegistro?->requiereTicket !== false
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

    /**
     * Catálogo único de trámites/procedimientos para ambos tipos de registro.
     * Incluye los ítems de entrega permitidos por cada trámite.
     */
    public function catalog(User $user): array
    {
        return Service::where('isActive', true)
            ->with(['entregaItems' => fn ($q) => $q->where('isActive', true)->orderBy('sortOrder')])
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get(['id', 'name', 'codigo', 'abreviation', 'type', 'sortOrder'])
            ->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'codigo' => $s->codigo,
                'abreviation' => $s->abreviation,
                'type' => $s->type,
                'entregaItems' => $s->entregaItems->map(fn (EntregaItem $i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    'codigo' => $i->codigo,
                ])->values()->all(),
            ])
            ->toArray();
    }

    /** Tipos de registro activos para el selector. */
    public function tiposRegistro(): array
    {
        return TipoRegistro::active()
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get(['id', 'name', 'codigo', 'requiereItems', 'requiereTicket', 'sortOrder'])
            ->toArray();
    }

    /** Ítems de entrega activos. */
    public function entregaItemsCatalog(): array
    {
        return EntregaItem::active()
            ->orderBy('sortOrder')
            ->orderBy('name')
            ->get(['id', 'name', 'codigo', 'sortOrder'])
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

    /** Totales por tipo de trámite y tipo de registro. */
    public function summaryByType(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): array {
        return $this->rangeQuery($user, $from, $to, $search)
            ->reorder()
            ->where('activo', true)
            ->select('serviceId', 'tipoRegistroId')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('serviceId', 'tipoRegistroId')
            ->orderByDesc('total')
            ->get()
            ->map(fn (TramiteRegistro $row) => [
                'serviceId' => $row->serviceId,
                'tipoRegistroId' => $row->tipoRegistroId,
                'name' => $row->service?->name ?? 'Sin tipo',
                'codigo' => $row->service?->codigo,
                'abreviation' => $row->service?->abreviation,
                'type' => $row->tipoRegistro?->codigo,
                'tipoRegistro' => $row->tipoRegistro?->name,
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Conteo de ítems entregados en el periodo.
     * Solo considera registros activos cuyo tipo de registro requiere ítems.
     *
     * @return array<int, array{itemId: string, name: string, codigo: string, total: int}>
     */
    public function entregaItemsSummary(
        User $user,
        string $from,
        string $to,
        ?string $search = null
    ): array {
        $start = BusinessDay::parseUtcDay($from);
        $end = BusinessDay::parseUtcDay($to)->addDay();

        $q = $this->baseQuery($user, $start, $end, $search)
            ->join('tipo_registros', 'tipo_registros.id', '=', 'tramite_registros.tipoRegistroId')
            ->join('tramite_registro_entrega_items as tri', 'tri.tramiteRegistroId', '=', 'tramite_registros.id')
            ->join('entrega_items', 'entrega_items.id', '=', 'tri.entregaItemId')
            ->where('tramite_registros.activo', true)
            ->where('tipo_registros.requiereItems', true)
            ->groupBy('entrega_items.id', 'entrega_items.name', 'entrega_items.codigo', 'entrega_items.sortOrder')
            ->orderBy('entrega_items.sortOrder')
            ->orderBy('entrega_items.name');

        return $q->get([
            'entrega_items.id as itemId',
            'entrega_items.name as name',
            'entrega_items.codigo as codigo',
            DB::raw('COUNT(*) as total'),
        ])->map(fn ($row) => [
            'itemId' => $row->itemId,
            'name' => $row->name,
            'codigo' => $row->codigo,
            'total' => (int) $row->total,
        ])->values()->all();
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

        return $this->baseQuery($user, $start, $end, $search)
            ->with([
                'service:id,name,codigo,abreviation,type',
                'tipoRegistro:id,name,codigo,requiereItems,requiereTicket',
                'entregaItems:id,name,codigo,sortOrder',
                'user:id,username,firstName,lastName,paternalSurname,maternalSurname',
            ])
            ->orderByDesc('date')
            ->orderByDesc('created_at');
    }

    private function baseQuery(
        User $user,
        Carbon $start,
        Carbon $end,
        ?string $search = null
    ): Builder {
        $q = TramiteRegistro::query()
            ->where('date', '>=', $start)
            ->where('date', '<', $end);

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
                            ->orWhere('abreviation', 'like', $like);
                    })
                    ->orWhereHas('tipoRegistro', function ($t) use ($like) {
                        $t->where('name', 'like', $like)
                            ->orWhere('codigo', 'like', $like);
                    })
                    ->orWhereHas('entregaItems', function ($i) use ($like) {
                        $i->where('name', 'like', $like)
                            ->orWhere('codigo', 'like', $like);
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
        $service = $this->assertServiceAllowed($data['serviceId']);

        $tipoRegistro = $this->resolveTipoRegistro(
            $data['tipoRegistroId'] ?? null,
            $service,
            null,
            true,
        );

        $ticket = $this->normalizeTicket($data['ticket'] ?? null);

        $this->assertTicketRequired($user, $ticket, $tipoRegistro);

        $estado = $data['estado'] ?? 'procesado';

        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw new HttpResponseException(
                ApiResponse::error('El estado debe ser procesado o recepcionado.', 422)
            );
        }

        $day = BusinessDay::parseUtcDay($data['date']);

        $this->assertDateAllowed($user, $day);

        $items = $this->resolveEntregaItems(
            $service,
            $tipoRegistro,
            $data['entregaItems'] ?? [],
            [],
            true,
        );

        // La duplicidad solamente genera una advertencia.
        $warning = $this->ticketWarning($ticket);

        $registro = TramiteRegistro::create([
            'userId' => $user->id,
            'serviceId' => $service->id,
            'tipoRegistroId' => $tipoRegistro?->id,
            'date' => $day,
            'placa' => $this->normalizePlaca($data['placa']),
            'observaciones' => $data['observaciones'] ?? null,
            'activo' => $data['activo'] ?? true,
            'ticket' => $ticket,
            'estado' => $estado,
        ]);

        $registro->entregaItems()->sync($items);

        $registro = $registro->fresh(['service', 'tipoRegistro', 'entregaItems', 'user']);

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

        $serviceChanged = false;

        if (isset($data['serviceId'])) {
            $service = $this->assertServiceAllowed($data['serviceId']);
            $serviceChanged = $service->id !== $registro->serviceId;
            $registro->serviceId = $service->id;
        }

        $service = $serviceChanged || ! $registro->service
            ? Service::findOrFail($registro->serviceId)
            : $registro->service;

        if (array_key_exists('tipoRegistroId', $data) || $serviceChanged) {
            $tipoRegistro = $this->resolveTipoRegistro(
                $data['tipoRegistroId'] ?? null,
                $service,
                $registro->tipoRegistro,
                false,
            );

            $registro->tipoRegistroId = $tipoRegistro?->id;
        } else {
            $tipoRegistro = $registro->tipoRegistro;
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

        // Para operadores, se exige que el ticket final no esté vacío
        // cuando el tipo de registro lo requiere.
        $this->assertTicketRequired(
            $user,
            $registro->ticket,
            $tipoRegistro
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

        if (
            array_key_exists('entregaItems', $data)
            || array_key_exists('tipoRegistroId', $data)
            || $serviceChanged
        ) {
            $current = $registro->entregaItems()->pluck('entrega_items.id')->all();

            $items = $this->resolveEntregaItems(
                $service,
                $tipoRegistro,
                $data['entregaItems'] ?? [],
                $current,
                false,
            );

            $registro->entregaItems()->sync($items);
        }

        $registro = $registro->fresh(['service', 'tipoRegistro', 'entregaItems', 'user']);

        return $this->attachTicketWarning($registro, $warning);
    }

    /**
     * Eliminación lógica.
     */
    public function deactivate(
        User $user,
        TramiteRegistro $registro
    ): TramiteRegistro {
        if (!in_array($user->role, ['ADMIN', 'JEFE'], true)) {
            throw new HttpResponseException(
                ApiResponse::error('No tiene permiso para eliminar registros. Solo puede editarlos.', 403)
            );
        }

        $registro->activo = false;
        $registro->save();

        return $registro->fresh(['service', 'tipoRegistro', 'entregaItems', 'user']);
    }

    private function assertServiceAllowed(string $id): Service
    {
        $service = Service::where('isActive', true)->find($id);

        if (!$service) {
            throw new HttpResponseException(
                ApiResponse::error('El tipo de trámite no existe o no está activo.', 400)
            );
        }

        return $service;
    }

    /**
     * Resuelve el tipo de registro. Si no se envía, se deriva del tipo del
     * servicio (compatibilidad con clientes anteriores).
     */
    private function resolveTipoRegistro(
        ?string $id,
        Service $service,
        ?TipoRegistro $current,
        bool $requireActive,
    ): ?TipoRegistro {
        if ($id === null || $id === '') {
            if ($current && $current->codigo === $service->type) {
                return $current;
            }

            return TipoRegistro::where('codigo', $service->type)->first();
        }

        $tipo = TipoRegistro::find($id);

        if (! $tipo || ($requireActive && ! $tipo->isActive)) {
            throw new HttpResponseException(
                ApiResponse::error('El tipo de registro no existe o no está activo.', 400)
            );
        }

        return $tipo;
    }

    /**
     * Valida y normaliza los ítems entregados.
     *
     * @param  array<int, string>  $selected
     * @param  array<int, string>  $current  Ítems que ya tenía el registro (edición).
     * @return array<int, string> IDs a sincronizar.
     */
    private function resolveEntregaItems(
        Service $service,
        ?TipoRegistro $tipoRegistro,
        array $selected,
        array $current,
        bool $requireActiveItems,
    ): array {
        if (! $tipoRegistro || ! $tipoRegistro->requiereItems) {
            return [];
        }

        $allowed = $service->entregaItems()
            ->where('entrega_items.isActive', true)
            ->pluck('entrega_items.id')
            ->all();

        // Sin configuración explícita: se permiten todos los ítems activos.
        if ($allowed === []) {
            $allowed = EntregaItem::active()->pluck('id')->all();
        }

        // En edición se conservan los ítems históricos aunque ya no estén permitidos.
        $permitted = array_values(array_unique(array_merge($allowed, $current)));

        $selected = array_values(array_unique(array_filter(
            array_map(fn ($id) => (string) $id, $selected),
            fn ($id) => $id !== '',
        )));

        if ($selected === []) {
            throw new HttpResponseException(
                ApiResponse::error('Debe seleccionar al menos un ítem de entrega.', 422)
            );
        }

        $invalid = array_diff($selected, $permitted);

        if ($invalid !== []) {
            throw new HttpResponseException(
                ApiResponse::error('Uno o más ítems de entrega no están permitidos para este trámite.', 422)
            );
        }

        return $selected;
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
