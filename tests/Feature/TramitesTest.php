<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TramitesTest extends TestCase
{
    use RefreshDatabase;

    private string $ingId;

    private string $entId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->ingId = Service::create([
            'name' => 'INSCRIPCION DIRECTA', 'abreviation' => 'ID',
            'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1,
        ])->id;

        $this->entId = Service::create([
            'name' => 'PLACA METALICA', 'abreviation' => 'PM',
            'type' => 'ENTREGA', 'isActive' => true, 'sortOrder' => 1,
        ])->id;
    }

    private function makeUser(string $username, string $role, array $extra = []): User
    {
        return app(UserService::class)->create(array_merge([
            'username' => $username,
            'password' => 'password',
            'role' => $role,
            'firstName' => 'N',
            'lastName' => 'U',
        ], $extra));
    }

    public function test_crear_listar_catalogo_y_orden(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $auth = fn () => $this->actingAs($op, 'sanctum');
        $today = now('UTC')->toDateString();

        // Catálogo filtrado por rol: solo servicios INGRESO.
        $auth()->getJson('/api/tramites/catalog')->assertOk()->assertJsonCount(1, 'data');

        $first = $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId,
            'placa' => 'abc123', 'observaciones' => 'primero',
        ])->assertCreated();
        $this->assertEquals('ABC123', $first->json('data.placa'));
        $this->assertTrue($first->json('data.activo'));

        $second = $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => 'ZZZ999',
        ])->assertCreated();

        // created_at descendente.
        TramiteRegistro::whereKey($first->json('data.id'))
            ->update(['created_at' => now('UTC')->subMinute()]);

        $list = $auth()->getJson("/api/tramites?date={$today}")->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertEquals($second->json('data.id'), $list->json('data.0.id'));
        $this->assertEquals($first->json('data.id'), $list->json('data.1.id'));
        $this->assertEquals('INSCRIPCION DIRECTA', $list->json('data.0.service.name'));

        // Un ADMIN ve ambos tipos en el catálogo.
        $admin = $this->makeUser('admin1', 'ADMIN');
        $this->actingAs($admin, 'sanctum')->getJson('/api/tramites/catalog')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_validaciones_rol_fechas_y_placa(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $auth = fn () => $this->actingAs($op->fresh(), 'sanctum');
        $today = now('UTC')->toDateString();
        $past = now('UTC')->subDays(3)->toDateString();
        $future = now('UTC')->addDay()->toDateString();

        // Placa con espacios / vacía.
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => 'AB 123',
        ])->assertStatus(400);
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId,
        ])->assertStatus(400);

        // Fecha futura bloqueada.
        $auth()->postJson('/api/tramites', [
            'date' => $future, 'serviceId' => $this->ingId, 'placa' => 'ABC123',
        ])->assertStatus(403);

        // Rol incorrecto para el tipo de trámite.
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->entId, 'placa' => 'ABC123',
        ])->assertStatus(403);

        // Fecha pasada: permitida por defecto, bloqueada sin canBackfill.
        $auth()->postJson('/api/tramites', [
            'date' => $past, 'serviceId' => $this->ingId, 'placa' => 'PAS123',
        ])->assertCreated();

        $op->update(['canBackfill' => false]);
        $auth()->postJson('/api/tramites', [
            'date' => $past, 'serviceId' => $this->ingId, 'placa' => 'PAS124',
        ])->assertStatus(403);

        // Servicio inactivo.
        Service::whereKey($this->ingId)->update(['isActive' => false]);
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => 'ABC123',
        ])->assertStatus(400);
    }

    public function test_editar_solo_dueno_o_admin(): void
    {
        $owner = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $other = $this->makeUser('ing2', 'OPERATOR_INGRESO');
        $admin = $this->makeUser('admin1', 'ADMIN');
        $today = now('UTC')->toDateString();

        $row = $this->actingAs($owner, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => 'ABC123',
        ])->assertCreated();
        $id = $row->json('data.id');

        // Otro operador no puede editar.
        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/tramites/{$id}", ['placa' => 'XYZ999'])
            ->assertStatus(403);

        // El dueño sí.
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/tramites/{$id}", ['placa' => 'DEF456', 'activo' => false])
            ->assertOk()->assertJsonPath('data.placa', 'DEF456');

        // El ADMIN también.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/tramites/{$id}", ['observaciones' => 'revisado', 'activo' => true])
            ->assertOk()->assertJsonPath('data.observaciones', 'revisado');
    }

    public function test_admin_ve_todos_los_registros_del_dia(): void
    {
        $op1 = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $op2 = $this->makeUser('ing2', 'OPERATOR_INGRESO');
        $admin = $this->makeUser('admin1', 'ADMIN');
        $today = now('UTC')->toDateString();

        foreach ([[$op1, 'AAA111'], [$op2, 'BBB222']] as [$op, $placa]) {
            $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
                'date' => $today, 'serviceId' => $this->ingId, 'placa' => $placa,
            ])->assertCreated();
        }

        $this->actingAs($op1, 'sanctum')->getJson("/api/tramites?date={$today}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->getJson("/api/tramites?date={$today}")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_inactivos_no_cuentan_en_dashboard_ni_totales(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $today = now('UTC')->toDateString();

        foreach ([['AAA111', true], ['BBB222', true], ['CCC333', false]] as [$placa, $activo]) {
            TramiteRegistro::create([
                'userId' => $op->id, 'serviceId' => $this->ingId,
                'date' => now('UTC')->startOfDay(), 'placa' => $placa, 'activo' => $activo,
            ]);
        }

        $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/summary?date={$today}")
            ->assertOk()->assertJsonPath('data.totalIngreso', 2);

        $this->actingAs($jefe, 'sanctum')->getJson("/api/totals/summary/day?date={$today}")
            ->assertOk()->assertJsonPath('data.total', 2);
    }
}
