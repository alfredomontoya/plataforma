<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de creación/edición de usuarios (docs 03 § Usuarios).
 * El cargo histórico vive en empleado_puestos (catálogos puestos/áreas);
 * aquí solo se gestiona la cuenta de sistema y su vínculo 1:1 al empleado.
 */
class UserService
{
    /**
     * Crea el usuario y asigna el rol Spatie. El vínculo al empleado es
     * obligatorio; la ficha de RRHH es la única fuente de nombres.
     * La auditoría registra al usuario autenticado como creador.
     */
    public function create(array $data, ?User $creator = null): User
    {
        return DB::transaction(function () use ($data, $creator) {
            $empleado = Empleado::findOrFail($data['empleadoId']);

            $user = User::create([
                'username' => $data['username'],
                'password' => $data['password'],
                'email' => $data['email'] ?? null,
                'role' => $data['role'],
                'isActive' => $data['isActive'] ?? true,
                'canBackfill' => $data['canBackfill'] ?? false,
                'empleadoId' => $empleado->id,
                'creadoPorUsuarioId' => $creator?->id,
            ]);

            $user->assignRole($user->role);

            return $user->fresh(['empleado', 'roles']);
        });
    }
}
