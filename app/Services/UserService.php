<?php

namespace App\Services;

use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de usuarios y cargos (docs 03 § Usuarios y cargos).
 */
class UserService
{
    /**
     * Crea el usuario + su primer Position activo y asigna el rol Spatie.
     */
    public function createWithPosition(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'username' => $data['username'],
                'password' => $data['password'],
                'role' => $data['role'],
                'title' => $data['title'] ?? 'NONE',
                'firstName' => $data['firstName'],
                'lastName' => $data['lastName'],
                'paternalSurname' => $data['paternalSurname'] ?? null,
                'maternalSurname' => $data['maternalSurname'] ?? null,
                'isActive' => $data['isActive'] ?? true,
                'canBackfill' => $data['canBackfill'] ?? false,
            ]);

            $position = Position::create([
                'userId' => $user->id,
                'title' => $user->title,
                'position' => $data['position'],
                'department' => $data['department'] ?? null,
                'isActive' => true,
                'startDate' => now('UTC'),
            ]);

            $user->update(['activePositionId' => $position->id]);
            $user->assignRole($user->role);

            return $user->fresh(['activePosition']);
        });
    }

    /**
     * Cambia el cargo: crea uno nuevo activo, desactiva el anterior (historial preservado).
     */
    public function changePosition(User $user, array $data): Position
    {
        return DB::transaction(function () use ($user, $data) {
            $user->positions()->where('isActive', true)->update([
                'isActive' => false,
                'endDate' => now('UTC'),
            ]);

            $position = Position::create([
                'userId' => $user->id,
                'title' => $data['title'] ?? $user->title,
                'position' => $data['position'],
                'department' => $data['department'] ?? null,
                'isActive' => true,
                'startDate' => now('UTC'),
            ]);

            $user->update(['activePositionId' => $position->id]);

            return $position;
        });
    }
}
