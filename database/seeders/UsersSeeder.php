<?php

namespace Database\Seeders;

use App\Models\Empleado;
use App\Models\User;
use App\Services\UserCredentialService;
use Illuminate\Database\Seeder;

/**
 * Crea una cuenta de sistema por cada empleado.
 *
 * Usuario/correo: iniciales de todos los nombres + primer apellido completo.
 * Ej.: Jose Antonio Perez Mendez → japerez. Los duplicados agregan 2, 3, etc.
 *
 * El empleado Alfredo Montoya Calderon (SOP) recibe la cuenta ADMIN fija
 * `amontoya`; el resto usa la contraseña demo `password`.
 *
 * Idempotente: la clave natural es `empleadoId`; no duplica cuentas.
 */
class UsersSeeder extends Seeder
{
    private const PASSWORD_DEFAULT = 'password';

    private const ADMIN_CI = '90000022';

    private const ADMIN_CREDENTIALS = [
        'username' => 'amontoya',
        'email' => 'amontoya@plataforma.test',
        'password' => '4725253',
    ];

    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('UsersSeeder omitido: no se siembran usuarios demo en producción.');

            return;
        }

        $empleados = Empleado::with(['puestoActual.puesto'])
            ->orderBy('ci')
            ->get();

        if ($empleados->isEmpty()) {
            $this->command?->warn('UsersSeeder omitido: no hay empleados para crear usuarios.');

            return;
        }

        $adminEmpleado = $empleados->firstWhere('ci', self::ADMIN_CI);

        if (! $adminEmpleado) {
            $this->command?->warn('UsersSeeder: no se encontró el empleado ADMIN con CI '.self::ADMIN_CI.'.');
        } else {
            $this->syncUser($adminEmpleado, 'ADMIN', self::ADMIN_CREDENTIALS);
        }

        foreach ($empleados as $empleado) {
            if ($adminEmpleado && $empleado->id === $adminEmpleado->id) {
                continue;
            }

            $this->syncUser($empleado, $this->roleFor($empleado));
        }

        if (! User::where('role', 'ADMIN')->where('isActive', true)->exists()) {
            $this->command?->warn('UsersSeeder: no quedó ningún usuario ADMIN activo.');
        }

        $this->command?->info('UsersSeeder: '.$empleados->count().' usuarios vinculados a empleados.');
    }

    private function syncUser(Empleado $empleado, string $role, ?array $fixed = null): void
    {
        $canBackfill = $role === 'ADMIN';
        $user = User::where('empleadoId', $empleado->id)->first();

        if ($user) {
            $data = [
                'role' => $role,
                'isActive' => $empleado->esActivo(),
                'canBackfill' => $canBackfill,
                'canBackfillEnabledAt' => $canBackfill ? now('UTC')->toDateString() : null,
            ];

            if ($fixed) {
                $data['username'] = $fixed['username'];
                $data['email'] = $fixed['email'];
                $data['password'] = $fixed['password'];
            }

            $user->update($data);
        } else {
            if ($fixed) {
                [$username, $email, $password] = [
                    $fixed['username'],
                    $fixed['email'],
                    $fixed['password'],
                ];
            } else {
                $credentials = app(UserCredentialService::class)->suggestFor($empleado);
                $username = $credentials['username'];
                $email = $credentials['email'];
                $password = self::PASSWORD_DEFAULT;
            }

            $user = User::create([
                'username' => $username,
                'password' => $password,
                'email' => $email,
                'role' => $role,
                'isActive' => $empleado->esActivo(),
                'canBackfill' => $canBackfill,
                'canBackfillEnabledAt' => $canBackfill ? now('UTC')->toDateString() : null,
                'empleadoId' => $empleado->id,
                'creadoPorUsuarioId' => null,
            ]);
        }

        if (! $user->hasRole($role)) {
            $user->syncRoles([$role]);
        }
    }

    private function roleFor(Empleado $empleado): string
    {
        $puesto = $empleado->puestoActual?->puesto;

        if (! $puesto) {
            return 'OPERATOR_INGRESO';
        }

        if ($puesto->sigla === 'JPV' || $puesto->esSupervisor) {
            return 'JEFE';
        }

        return $puesto->sigla === 'OE' ? 'OPERATOR_ENTREGA' : 'OPERATOR_INGRESO';
    }
}
