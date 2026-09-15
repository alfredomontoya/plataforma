<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
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
        return app(UserService::class)->createWithPosition([
            'username' => 'admin1', 'password' => 'password', 'role' => 'ADMIN',
            'title' => 'LIC', 'firstName' => 'A', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
    }

    public function test_usuarios_crud_completo(): void
    {
        $admin = $this->admin();
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        // operador no puede
        $op = app(UserService::class)->createWithPosition([
            'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
            'title' => 'NONE', 'firstName' => 'I', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
        $this->actingAs($op, 'sanctum')->getJson('/api/users')->assertStatus(403);

        // crear + cargo inicial + rol Spatie
        $created = $auth()->postJson('/api/users', [
            'username' => 'ing2', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
            'title' => 'NONE', 'firstName' => 'I2', 'lastName' => 'U',
            'position' => 'Ventanilla', 'department' => 'La Paz',
        ])->assertCreated()->assertJson(['success' => true]);
        $id = $created->json('data.id');
        $this->assertNotEmpty($created->json('data.active_position'));

        // filtros + orden por cargo
        $auth()->getJson('/api/users?search=ing2&role=OPERATOR_INGRESO&isActive=true&sortBy=position&sortOrder=asc')
            ->assertOk()->assertJsonCount(1, 'data');

        // editar rol sincroniza Spatie + backfill marca el día
        $auth()->patchJson("/api/users/{$id}", ['role' => 'JEFE', 'canBackfill' => true])->assertOk();
        $this->assertTrue(\App\Models\User::find($id)->hasRole('JEFE'));
        $this->assertEquals(now('UTC')->toDateString(), \App\Models\User::find($id)->canBackfillEnabledAt->toDateString());

        // cambiar cargo preserva historial
        $auth()->patchJson("/api/users/{$id}/position", ['position' => 'Supervisor', 'department' => 'Cbba'])->assertOk();
        $this->assertEquals(2, \App\Models\User::find($id)->positions()->count());

        // desactivar es lógico
        $auth()->deleteJson("/api/users/{$id}")->assertOk();
        $this->assertFalse(\App\Models\User::find($id)->isActive);
        $this->assertDatabaseHas('users', ['id' => $id]);
    }

    public function test_servicios_catalogo_y_crud(): void
    {
        $admin = $this->admin();
        $op = app(UserService::class)->createWithPosition([
            'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
            'title' => 'NONE', 'firstName' => 'I', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
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
        $auth()->patchJson('/api/services/admin/' . $created->json('data.id'), ['sortOrder' => 5, 'abreviation' => 'Serv. Y'])->assertOk()
            ->assertJsonPath('data.abreviation', 'Serv. Y');
        $auth()->deleteJson('/api/services/admin/' . $svc->id)->assertOk();
        $this->assertFalse($svc->fresh()->isActive);
        // desactivado ya no sale en catálogo
        $this->actingAs($op, 'sanctum')->getJson('/api/services/type/INGRESO')->assertOk()->assertJsonCount(0, 'data');
    }
}
