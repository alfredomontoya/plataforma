<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\UserService;
use App\Support\Mulberry32;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Base (admin, jefe, ing1-5/ent1-5, password `password`) + N aleatorios
 * mitad/mitad con PRNG mulberry32 (docs 03 § Seeders).
 */
class UsersSeeder extends Seeder
{
    private const FIRST = ['Juan', 'Maria', 'Carlos', 'Ana', 'Luis', 'Rosa', 'Miguel', 'Carmen', 'Jorge', 'Lucia', 'Pedro', 'Elena', 'Diego', 'Sofia', 'Andres', 'Paola', 'Fernando', 'Gabriela', 'Ricardo', 'Daniela'];
    private const PATERNO = ['Quispe', 'Mamani', 'Condori', 'Huanca', 'Apaza', 'Choque', 'Flores', 'Vargas', 'Rojas', 'Torres', 'Gutierrez', 'Paredes', 'Cruz', 'Ramos', 'Ortega', 'Salazar', 'Mendoza', 'Aguilar', 'Castillo', 'Vega'];
    private const MATERNO = ['Perez', 'Gomez', 'Lopez', 'Diaz', 'Sanchez', 'Ramirez', 'Castro', 'Morales', 'Ortiz', 'Chavez'];
    private const CARGOS = ['Operador de Registro', 'Auxiliar de Trámites', 'Técnico de Ventanilla', 'Verificador'];
    private const DEPTOS = ['La Paz', 'Cochabamba', 'Santa Cruz'];

    public function __construct(
        private int $operators = 10,
        private int $userSeed = 42,
    ) {}

    public function run(): void
    {
        $svc = app(UserService::class);

        $base = [
            ['admin', 'ADMIN', 'ING', 'Admin', 'Sistema'],
            ['jefe', 'JEFE', 'LIC', 'Jefe', 'Oficina'],
        ];
        for ($i = 1; $i <= 5; $i++) {
            $base[] = ["ing{$i}", 'OPERATOR_INGRESO', 'NONE', "Ing{$i}", 'Operador'];
            $base[] = ["ent{$i}", 'OPERATOR_ENTREGA', 'NONE', "Ent{$i}", 'Operador'];
        }
        foreach ($base as [$username, $role, $title, $first, $last]) {
            User::firstOrCreate(['username' => $username], [
                'password' => 'password', 'role' => $role, 'title' => $title,
                'firstName' => $first, 'lastName' => $last,
                'isActive' => true, 'canBackfill' => false,
            ]);
            $user = User::where('username', $username)->first();
            if (! $user->activePositionId) {
                app(UserService::class)->changePosition($user, [
                    'title' => $title, 'position' => 'Cargo Base', 'department' => 'La Paz',
                ]);
            }
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        $rng = new Mulberry32($this->userSeed);
        $taken = User::pluck('username')->flip()->toArray();
        $created = 0;
        $n = 0;
        while ($created < $this->operators) {
            // sorteos incondicionales (idempotencia)
            $fi = $rng->int(0, count(self::FIRST) - 1);
            $pi = $rng->int(0, count(self::PATERNO) - 1);
            $mi = $rng->int(0, count(self::MATERNO) - 1);
            $ci = $rng->int(0, count(self::CARGOS) - 1);
            $di = $rng->int(0, count(self::DEPTOS) - 1);
            $n++;

            $username = strtolower(Str::ascii(substr(self::FIRST[$fi], 0, 1) . self::PATERNO[$pi]));
            $username = preg_replace('/[^a-z]/', '', $username) ?: "op{$n}";
            if (isset($taken[$username])) {
                $username .= $n;
            }
            if (isset($taken[$username])) {
                continue;
            }
            $taken[$username] = true;

            $svc->createWithPosition([
                'username' => $username,
                'password' => 'password',
                'role' => $created % 2 === 0 ? 'OPERATOR_INGRESO' : 'OPERATOR_ENTREGA',
                'title' => 'NONE',
                'firstName' => self::FIRST[$fi],
                'lastName' => self::PATERNO[$pi],
                'paternalSurname' => self::PATERNO[$pi],
                'maternalSurname' => self::MATERNO[$mi],
                'position' => self::CARGOS[$ci],
                'department' => self::DEPTOS[$di],
            ]);
            $created++;
        }
    }
}
