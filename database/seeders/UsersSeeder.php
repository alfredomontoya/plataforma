<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 10 usuarios base fijos (sin aleatorios):
 * admin (ADMIN), jefe (JEFE) + 8 operadores (username = inicial del nombre + apellido).
 * Todos con password `password`. Idempotente: no duplica por username.
 *
 * El título académico y el historial de cargos ya no viven en `users`: se
 * movieron al módulo de RRHH (`tratamientos` → `empleados` → `empleado_puestos`
 * → `puestos`). Estas cuentas de sistema se siembran sin vínculo a empleado
 * (`empleadoId` es nullable), por lo que no se asignan títulos aquí.
 *
 * Todos los usuarios sembrados quedan con canBackfill habilitado por defecto
 * (pueden registrar fechas pasadas). El permiso es persistente y un ADMIN
 * puede desactivarlo por usuario.
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('UsersSeeder omitido: no se siembran usuarios demo en producción.');

            return;
        }

        $base = [
            ['admin', 'ADMIN', 'Admin', 'Sistema'],
            ['chuachumanca', 'JEFE', 'Claudia', 'Chuachumanca'],
            ['jcamacho', 'OPERATOR_INGRESO', 'Jaime', 'Camacho'],
            ['jcarreno', 'OPERATOR_INGRESO', 'Jose Ernesto', 'Carreno'],
            ['arodriguez', 'OPERATOR_INGRESO', 'Alfredo', 'Rodriguez'],
            ['rsuarez', 'OPERATOR_ENTREGA', 'Robin', 'Suarez'],
            ['yvelazco', 'JEFE', 'Yderf', 'Velazco'],
            ['gchoque', 'OPERATOR_INGRESO', 'Gonzalo', 'Choque'],
            ['rflores', 'OPERATOR_ENTREGA', 'Rosa', 'Flores'],
            ['cvargas', 'OPERATOR_ENTREGA', 'Carmen', 'Vargas'],
        ];

        foreach ($base as [$username, $role, $first, $last]) {
            $user = User::firstOrCreate(['username' => $username], [
                'password' => 'password', 'role' => $role,
                'firstName' => $first, 'lastName' => $last,
                'isActive' => true, 'canBackfill' => true,
            ]);

            if (! $user->canBackfill) {
                $user->update(['canBackfill' => true]);
            }

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }
    }
}
