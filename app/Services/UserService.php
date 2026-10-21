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
     * Crea el usuario y asigna el rol Spatie. Si trae `empleadoId`, los
     * nombres de la cuenta se copian desde el empleado (el empleado manda)
     * y la auditoría registra al usuario autenticado como creador.
     */
    public function create(array $data, ?User $creator = null): User
    {
        return DB::transaction(function () use ($data, $creator) {
            $empleado = isset($data['empleadoId'])
                ? Empleado::findOrFail($data['empleadoId'])
                : null;

            $user = User::create([
                'username' => $data['username'],
                'password' => $data['password'],
                'email' => $data['email'] ?? null,
                'role' => $data['role'],
                'firstName' => $empleado?->nombre ?? $data['firstName'],
                'lastName' => $empleado?->apellidos ?? $data['lastName'],
                'paternalSurname' => $empleado ? null : ($data['paternalSurname'] ?? null),
                'maternalSurname' => $empleado ? null : ($data['maternalSurname'] ?? null),
                'isActive' => $data['isActive'] ?? true,
                'canBackfill' => $data['canBackfill'] ?? false,
                'empleadoId' => $empleado?->id,
                'creadoPorUsuarioId' => $creator?->id,
            ]);

            $user->assignRole($user->role);

            return $user->fresh(['empleado', 'roles']);
        });
    }
}
