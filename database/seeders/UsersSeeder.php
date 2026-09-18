<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\Seeder;

/**
 * 10 usuarios base fijos (sin aleatorios):
 * admin (ADMIN), jefe (JEFE) + 8 operadores (username = inicial del nombre + apellido).
 * Todos con password `password`. Idempotente: no duplica por username.
 * Los ADMIN quedan con canBackfill habilitado el día de siembra (permite
 * registrar fechas pasadas; la regla lo auto-revoca al día siguiente).
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        $base = [
            ['admin', 'ADMIN', 'ING', 'Admin', 'Sistema'],
            ['jefe', 'JEFE', 'LIC', 'Jefe', 'Oficina'],
            ['jquispe', 'OPERATOR_INGRESO', 'NONE', 'Juan', 'Quispe'],
            ['cmamani', 'OPERATOR_INGRESO', 'NONE', 'Carlos', 'Mamani'],
            ['lcondori', 'OPERATOR_INGRESO', 'NONE', 'Luis', 'Condori'],
            ['mhuanca', 'OPERATOR_INGRESO', 'NONE', 'Miguel', 'Huanca'],
            ['mapaza', 'OPERATOR_ENTREGA', 'NONE', 'María', 'Apaza'],
            ['achoque', 'OPERATOR_ENTREGA', 'NONE', 'Ana', 'Choque'],
            ['rflores', 'OPERATOR_ENTREGA', 'NONE', 'Rosa', 'Flores'],
            ['cvargas', 'OPERATOR_ENTREGA', 'NONE', 'Carmen', 'Vargas'],
        ];
        foreach ($base as [$username, $role, $title, $first, $last]) {
            User::firstOrCreate(['username' => $username], [
                'password' => 'password', 'role' => $role, 'title' => $title,
                'firstName' => $first, 'lastName' => $last,
                'isActive' => true, 'canBackfill' => false,
            ]);
            $user = User::where('username', $username)->first();
            if ($role === 'ADMIN') {
                $user->update([
                    'canBackfill' => true,
                    'canBackfillEnabledAt' => now('UTC')->toDateString(),
                ]);
            }
            if (! $user->activePositionId) {
                app(UserService::class)->changePosition($user, [
                    'title' => $title, 'position' => 'Cargo Base', 'department' => 'La Paz',
                ]);
            }
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }
}
