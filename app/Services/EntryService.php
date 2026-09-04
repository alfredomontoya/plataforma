<?php

namespace App\Services;

use App\Models\Entry;
use App\Models\Service;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Registro diario (docs 03 § Entradas): match tipo rol↔servicios + regla backfill.
 */
class EntryService
{
    public function assertRoleForType(User $user, string $type): void
    {
        $map = [
            'OPERATOR_INGRESO' => 'INGRESO',
            'OPERATOR_ENTREGA' => 'ENTREGA',
        ];

        if ($user->role === 'ADMIN') {
            return;
        }
        if (($map[$user->role] ?? null) !== $type) {
            throw new HttpResponseException(ApiResponse::error('Su rol no puede registrar este tipo.', 403));
        }
    }

    /**
     * Sin permiso solo hoy o futuro; con canBackfill habilitado hoy, cualquier pasado.
     * Si el flag sigue activo otro día, se auto-revoca.
     */
    public function assertDateAllowed(User $user, Carbon $dayUtc): void
    {
        $today = BusinessDay::todayUtc();

        if ($dayUtc->gte($today)) {
            return;
        }

        $enabledToday = $user->canBackfill
            && $user->canBackfillEnabledAt
            && $user->canBackfillEnabledAt->isSameDay($today);

        if ($user->canBackfill && ! $enabledToday) {
            $user->update(['canBackfill' => false]);
        }

        if (! $enabledToday) {
            throw new HttpResponseException(
                ApiResponse::error('No tiene permiso para registrar fechas pasadas.', 403)
            );
        }
    }

    /** Crea/actualiza en lote (upsert por usuario×servicio×día). Devuelve las filas. */
    public function storeBatch(User $user, string $day, string $type, array $items): array
    {
        $this->assertRoleForType($user, $type);
        $dayUtc = BusinessDay::parseUtcDay($day);
        $this->assertDateAllowed($user, $dayUtc);

        $services = Service::whereIn('id', collect($items)->pluck('serviceId'))
            ->where('isActive', true)->get()->keyBy('id');

        foreach ($items as $item) {
            $service = $services->get($item['serviceId']);
            if (! $service || $service->type !== $type) {
                throw new HttpResponseException(
                    ApiResponse::error('El tipo debe coincidir con el tipo de cada servicio.', 400)
                );
            }
        }

        return DB::transaction(function () use ($user, $dayUtc, $type, $items) {
            $rows = [];
            foreach ($items as $item) {
                $rows[] = Entry::updateOrCreate(
                    ['userId' => $user->id, 'serviceId' => $item['serviceId'], 'date' => $dayUtc],
                    ['type' => $type, 'quantity' => $item['quantity']]
                )->fresh(['service']);
            }
            return $rows;
        });
    }

    public function updateQuantity(User $user, Entry $entry, int $quantity): Entry
    {
        if ($user->role !== 'ADMIN' && $entry->userId !== $user->id) {
            throw new HttpResponseException(ApiResponse::error('Sin permiso para esta acción.', 403));
        }
        $this->assertDateAllowed($user, $entry->date->copy()->startOfDay());

        $entry->update(['quantity' => $quantity]);

        return $entry->fresh(['service']);
    }
}
