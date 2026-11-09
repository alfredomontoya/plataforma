<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn(
                'UsersSeeder omitido: no se siembran usuarios demo en producción.'
            );

            return;
        }

        $base = [
            // username, role, firstName, lastName, paternalSurname, maternalSurname

            ['arodriguez', 'OPERATOR_INGRESO', 'Alfredo', 'Rodriguez', 'Maldonado', null],
            ['rsuarez', 'OPERATOR_INGRESO', 'Robin', 'Suarez', 'Ribera', null],
            ['bayllon', 'OPERATOR_INGRESO', 'Beymar Fernando', 'Ayllon', 'Camacho', null],
            ['srojas', 'OPERATOR_INGRESO', 'Sandra', 'Rojas', 'Arteaga', null],

            ['vmelgar', 'OPERATOR_INGRESO', 'Virginia', 'Melgar', 'Ambló', null],
            ['alopez', 'OPERATOR_INGRESO', 'Angela', 'Lopez', 'Duran', null],

            ['jcamacho', 'OPERATOR_INGRESO', 'Jaime', 'Camacho', 'Mosa', null],
            ['gchoque', 'OPERATOR_INGRESO', 'Gonzalo', 'Choque', 'Ayala', null],

            ['esalvatierra', 'OPERATOR_ENTREGA', 'Erwin', 'Salvatierra', 'Abdalla', null],
            ['jcarreno', 'OPERATOR_ENTREGA', 'Jose Ernesto', 'Carreño', 'Gutierrez', null],

            ['gcoca', 'OPERATOR_INGRESO', 'Guery Rolando', 'Coca', 'Velasco', null],

            ['chuachumanca', 'ADMIN', 'Claudia', 'Huachumanca', 'Changaray', null],

            ['rmacabapi', 'OPERATOR_INGRESO', 'Regina', 'Macabapi', 'Teco', null],
            ['kjaldin', 'OPERATOR_INGRESO', 'Kelly Yerline', 'Jaldin', 'Mendoza', null],
            ['jcarvajal', 'OPERATOR_INGRESO', 'Julio Cesar', 'Carvajal', null, null],
            ['plopez', 'OPERATOR_INGRESO', 'Pedro Luis', 'Lopez', 'Moreno', null],

            ['jsempertegui', 'OPERATOR_INGRESO', 'Jose Luis', 'Sempertegui', 'Noriega', null],

            ['gpastora', 'OPERATOR_INGRESO', 'Gabriela', 'Pastora', 'Tellez', null],
            ['erojas', 'OPERATOR_INGRESO', 'Ernestina', 'Rojas', 'Morales', null],
            ['rclaure', 'OPERATOR_INGRESO', 'Rebeca', 'Claure', 'Linarez', null],

            ['jramos', 'OPERATOR_INGRESO', 'Jhonatan Gabriel', 'Ramos', null, null],
            ['lflorida', 'OPERATOR_ENTREGA', 'Laura', 'Florida', null, null],

            ['yvelazco', 'JEFE', 'Ydderf', 'Velasco', 'Cespedes', null],
        ];

        foreach ($base as [
            $username,
            $role,
            $first,
            $last,
            $paternal,
            $maternal,
        ]) {
            $user = User::firstOrCreate(
                ['username' => $username],
                [
                    'password' => 'password',
                    'role' => $role,
                    'firstName' => $first,
                    'lastName' => $last,
                    'paternalSurname' => $paternal,
                    'maternalSurname' => $maternal,
                    'isActive' => true,
                    'canBackfill' => true,
                ]
            );

            if (! $user->canBackfill) {
                $user->update(['canBackfill' => true]);
            }

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        $this->command?->info(
            count($base) . ' usuarios procesados correctamente.'
        );
    }
}
