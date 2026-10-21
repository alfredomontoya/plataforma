<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AreaSeeder;
use Database\Seeders\EmpleadoPvehSeeder;
use Database\Seeders\PuestoPvehSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TratamientoSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsersServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return $this->userFor('admin1', 'ADMIN');
    }

    public function test_usuarios_crud_completo(): void
    {
        $admin = $this->admin();
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        // operador no puede
        $op = $this->userFor('ing1', 'OPERATOR_INGRESO');
        $this->actingAs($op, 'sanctum')->getJson('/api/users')->assertStatus(403);

        // crear + rol Spatie + vínculo obligatorio al empleado
        $empleado = $this->empleadoDemo(['nombre' => 'Zoe', 'apellidos' => 'Unica']);
        $created = $auth()->postJson('/api/users', [
            'username' => 'ing2', 'password' => 'password', 'email' => 'ing2@test.local',
            'role' => 'OPERATOR_INGRESO', 'empleadoId' => $empleado->id,
        ])->assertCreated()->assertJson(['success' => true]);
        $id = $created->json('data.id');
        $this->assertSame('ing2@test.local', $created->json('data.email'));
        $this->assertSame($empleado->id, $created->json('data.empleadoId'));

        // un empleado solo puede tener una cuenta
        $auth()->postJson('/api/users', [
            'username' => 'ing3', 'password' => 'password', 'email' => 'ing3@test.local',
            'role' => 'OPERATOR_INGRESO', 'empleadoId' => $empleado->id,
        ])->assertStatus(400);

        // filtros + orden por usuario, y búsqueda por nombre del empleado
        $auth()->getJson('/api/users?search=ing2&role=OPERATOR_INGRESO&isActive=true&sortBy=username&sortOrder=asc')
            ->assertOk()->assertJsonCount(1, 'data');
        $auth()->getJson('/api/users?search=Zoe')->assertOk()->assertJsonCount(1, 'data');

        // editar rol sincroniza Spatie + backfill marca el día
        $auth()->patchJson("/api/users/{$id}", ['role' => 'JEFE', 'canBackfill' => true])->assertOk();
        $this->assertTrue(User::find($id)->hasRole('JEFE'));
        $this->assertEquals(now('UTC')->toDateString(), User::find($id)->canBackfillEnabledAt->toDateString());

        // desactivar es lógico
        $auth()->deleteJson("/api/users/{$id}")->assertOk();
        $this->assertFalse(User::find($id)->isActive);
        $this->assertDatabaseHas('users', ['id' => $id]);
    }

    public function test_seed_crea_admin_y_cuentas_para_toda_la_plantilla(): void
    {
        $this->seed([
            AreaSeeder::class,
            PuestoPvehSeeder::class,
            TratamientoSeeder::class,
            EmpleadoPvehSeeder::class,
        ]);

        (new UsersSeeder)->run();

        $admin = User::where('username', 'amontoya')->firstOrFail();
        $this->assertSame('ADMIN', $admin->role);
        $this->assertTrue($admin->canBackfill);
        $this->assertSame('90000022', $admin->empleado->ci);
        $this->assertSame('Alfredo', $admin->empleado->nombre);
        $this->assertEquals(now('UTC')->toDateString(), $admin->canBackfillEnabledAt->toDateString());
        $this->assertTrue($admin->hasRole('ADMIN'));
        $this->assertTrue(Hash::check('4725253', $admin->password));

        $this->assertSame(22, User::count());
        $this->assertSame(22, Empleado::has('usuario')->count());

        $oe = Empleado::whereHas('puestoActual.puesto', fn ($q) => $q->where('sigla', 'OE'))->firstOrFail();
        $op = User::where('empleadoId', $oe->id)->firstOrFail();
        $this->assertFalse($op->canBackfill);
        $this->assertSame('OPERATOR_ENTREGA', $op->role);
        $this->assertTrue(Hash::check('password', $op->password));

        $jpv = Empleado::whereHas('puestoActual.puesto', fn ($q) => $q->where('sigla', 'JPV'))->firstOrFail();
        $this->assertSame('JEFE', User::where('empleadoId', $jpv->id)->value('role'));

        // Re-siembra idempotente: mantiene el flag en el admin existente.
        $admin->update(['canBackfill' => false]);
        (new UsersSeeder)->run();
        $this->assertTrue($admin->fresh()->canBackfill);
        $this->assertSame(22, User::count());
    }

    public function test_usuarios_se_generan_con_iniciales_y_sufijo(): void
    {
        $alfredo = $this->empleadoDemo([
            'ci' => '90000022', 'nombre' => 'Alfredo', 'apellidos' => 'Montoya Calderon',
        ]);
        $jose = $this->empleadoDemo([
            'ci' => '99000001', 'nombre' => 'José Antonio', 'apellidos' => 'Pérez Méndez',
        ]);
        $joseDuplicado = $this->empleadoDemo([
            'ci' => '99000002', 'nombre' => 'Jose Antonio', 'apellidos' => 'Perez Mendez',
        ]);

        (new UsersSeeder)->run();

        $this->assertSame('amontoya', User::where('empleadoId', $alfredo->id)->value('username'));
        $this->assertSame('japerez', User::where('empleadoId', $jose->id)->value('username'));
        $this->assertSame('japerez2', User::where('empleadoId', $joseDuplicado->id)->value('username'));
        $this->assertSame('japerez@plataforma.test', User::where('empleadoId', $jose->id)->value('email'));

        (new UsersSeeder)->run();
        $this->assertSame(3, User::count());
    }

    public function test_sugerencia_de_credenciales_por_empleado_y_duplicados(): void
    {
        $admin = $this->admin();
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        $empleado = $this->empleadoDemo([
            'ci' => '99000003',
            'nombre' => 'José Antonio',
            'apellidos' => 'Pérez Méndez',
            'estado' => Empleado::ESTADO_ACTIVO,
        ]);

        $auth()->getJson('/api/users/suggest-username?empleadoId='.$empleado->id)
            ->assertOk()
            ->assertJsonPath('data.username', 'japerez')
            ->assertJsonPath('data.email', 'japerez@plataforma.test');

        // El usuario y el correo base ya están ocupados: se sugiere el sufijo 2.
        $this->userFor('japerez', 'OPERATOR_INGRESO', ['email' => 'japerez@plataforma.test']);

        $auth()->getJson('/api/users/suggest-username?empleadoId='.$empleado->id)
            ->assertOk()
            ->assertJsonPath('data.username', 'japerez2')
            ->assertJsonPath('data.email', 'japerez2@plataforma.test');

        // Un empleado con cuenta no puede recibir otra.
        $auth()->postJson('/api/users', [
            'username' => 'josecuenta', 'password' => 'password', 'email' => 'josecuenta@test.local',
            'role' => 'OPERATOR_INGRESO', 'empleadoId' => $empleado->id,
        ])->assertCreated();

        $auth()->getJson('/api/users/suggest-username?empleadoId='.$empleado->id)
            ->assertStatus(409);

        // Tampoco se sugiere para empleados inactivos.
        $inactivo = $this->empleadoDemo([
            'ci' => '99000004', 'nombre' => 'Ana', 'apellidos' => 'Pérez',
            'estado' => Empleado::ESTADO_INACTIVO,
        ]);

        $auth()->getJson('/api/users/suggest-username?empleadoId='.$inactivo->id)
            ->assertStatus(400);
    }

    public function test_servicios_catalogo_y_crud(): void
    {
        $admin = $this->admin();
        $op = $this->userFor('ing1', 'OPERATOR_INGRESO');
        $svc = Service::create(['name' => 'SERV X', 'abreviation' => 'SX', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1]);

        // catálogo por tipo (autenticado) y admin requerido para el resto
        $this->actingAs($op, 'sanctum')->getJson('/api/services/type/INGRESO')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($op, 'sanctum')->getJson('/api/services/admin')->assertStatus(403);

        $auth = fn () => $this->actingAs($admin, 'sanctum');
        $auth()->postJson('/api/services/admin', ['name' => 'SERV X'])->assertStatus(400); // duplicado
        $created = $auth()->postJson('/api/services/admin', ['name' => 'SERV Y', 'codigo' => 'SY', 'type' => 'ENTREGA'])->assertCreated();
        $this->assertEquals('SY', $created->json('data.codigo'));
        $auth()->postJson('/api/services/admin', ['name' => 'SERV Z', 'codigo' => 'SY', 'type' => 'ENTREGA'])->assertStatus(400); // codigo duplicado
        $auth()->patchJson('/api/services/admin/'.$created->json('data.id'), ['sortOrder' => 5, 'abreviation' => 'Serv. Y'])->assertOk()
            ->assertJsonPath('data.abreviation', 'Serv. Y');
        $auth()->deleteJson('/api/services/admin/'.$svc->id)->assertOk();
        $this->assertFalse($svc->fresh()->isActive);
        // desactivado ya no sale en catálogo
        $this->actingAs($op, 'sanctum')->getJson('/api/services/type/INGRESO')->assertOk()->assertJsonCount(0, 'data');
    }
}
