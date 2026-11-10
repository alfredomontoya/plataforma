<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin()
    {
        return app(UserService::class)->create([
            'username' => 'admin1', 'password' => 'password', 'role' => 'ADMIN',
            'firstName' => 'A', 'lastName' => 'U',
        ]);
    }

    public function test_usuarios_crud_completo(): void
    {
        $admin = $this->admin();
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        // operador no puede
        $op = app(UserService::class)->create([
            'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
            'firstName' => 'I', 'lastName' => 'U',
        ]);
        $this->actingAs($op, 'sanctum')->getJson('/api/users')->assertStatus(403);

        // crear + rol Spatie
        $created = $auth()->postJson('/api/users', [
            'username' => 'ing2', 'password' => 'password', 'email' => 'ing2@test.local',
            'role' => 'OPERATOR_INGRESO', 'firstName' => 'I2', 'lastName' => 'U',
        ])->assertCreated()->assertJson(['success' => true]);
        $id = $created->json('data.id');
        $this->assertSame('ing2@test.local', $created->json('data.email'));
        $this->assertNull($created->json('data.empleadoId'));

        // filtros + orden por username
        $auth()->getJson('/api/users?search=ing2&role=OPERATOR_INGRESO&isActive=true&sortBy=username&sortOrder=asc')
            ->assertOk()->assertJsonCount(1, 'data');

        // editar rol sincroniza Spatie y mantiene el backfill habilitado
        $auth()->patchJson("/api/users/{$id}", ['role' => 'JEFE', 'canBackfill' => true])->assertOk();
        $this->assertTrue(User::find($id)->hasRole('JEFE'));
        $this->assertTrue(User::find($id)->canBackfill);

        // desactivar es lógico
        $auth()->deleteJson("/api/users/{$id}")->assertOk();
        $this->assertFalse(User::find($id)->isActive);
        $this->assertDatabaseHas('users', ['id' => $id]);
    }

    public function test_seed_todos_con_backfill_por_defecto(): void
    {
        (new UsersSeeder)->run();

        $admin = User::where('username', 'amontoya')->firstOrFail();
        $this->assertTrue($admin->canBackfill);

        $op = User::where('username', 'jcamacho')->firstOrFail();
        $this->assertTrue($op->canBackfill);

        // Re-siembra idempotente: vuelve a dejar el permiso por defecto.
        $op->update(['canBackfill' => false]);
        (new UsersSeeder)->run();
        $this->assertTrue($op->fresh()->canBackfill);
    }

    public function test_servicios_catalogo_y_crud(): void
    {
        $admin = $this->admin();
        $op = app(UserService::class)->create([
            'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
            'firstName' => 'I', 'lastName' => 'U',
        ]);
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
