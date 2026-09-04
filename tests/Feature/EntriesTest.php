<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntriesTest extends TestCase
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

    private function makeUser(string $username, string $role, array $extra = [])
    {
        return app(UserService::class)->createWithPosition(array_merge([
            'username' => $username, 'password' => 'password', 'role' => $role,
            'title' => 'LIC', 'firstName' => 'T', 'lastName' => 'U',
            'position' => 'C', 'department' => 'D',
        ], $extra));
    }

    public function test_operador_registra_hoy_en_lote(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $today = now('UTC')->toDateString();

        $res = $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => $today, 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 5]],
        ]);

        $res->assertCreated()->assertJson(['success' => true]);
        $this->assertDatabaseHas('entries', ['userId' => $op->id, 'quantity' => 5]);
    }

    public function test_tipo_debe_coincidir_con_servicios_y_rol(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');

        // servicio de otro tipo
        $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => now('UTC')->toDateString(), 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->entId, 'quantity' => 1]],
        ])->assertStatus(400);

        // rol de otro tipo
        $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => now('UTC')->toDateString(), 'type' => 'ENTREGA',
            'items' => [['serviceId' => $this->entId, 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_backfill_403_y_autorevocacion(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $past = now('UTC')->subDays(3)->toDateString();

        // sin permiso → 403
        $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => $past, 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 2]],
        ])->assertStatus(403)->assertJson(['success' => false]);

        // con permiso habilitado hoy → permitido
        $op->update(['canBackfill' => true, 'canBackfillEnabledAt' => now('UTC')->toDateString()]);
        $this->actingAs($op->fresh(), 'sanctum')->postJson('/api/entries', [
            'date' => $past, 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 2]],
        ])->assertCreated();

        // permiso de otro día → se auto-revoca y 403
        $op->update(['canBackfill' => true, 'canBackfillEnabledAt' => now('UTC')->subDay()->toDateString()]);
        $this->actingAs($op->fresh(), 'sanctum')->postJson('/api/entries', [
            'date' => $past, 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 1]],
        ])->assertStatus(403);
        $this->assertFalse($op->fresh()->canBackfill);
    }

    public function test_patch_solo_cantidad_y_dueno_o_admin(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $other = $this->makeUser('ing2', 'OPERATOR_INGRESO');
        $admin = $this->makeUser('admin1', 'ADMIN');

        $id = $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => now('UTC')->toDateString(), 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 5]],
        ])->json('data.0.id');

        $this->actingAs($other, 'sanctum')->patchJson("/api/entries/{$id}", ['quantity' => 9])
            ->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->patchJson("/api/entries/{$id}", ['quantity' => 9])
            ->assertOk();
        $this->assertDatabaseHas('entries', ['id' => $id, 'quantity' => 9]);
    }

    public function test_me_solo_propias_y_index_solo_jefe_admin(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $jefe = $this->makeUser('jefe1', 'JEFE');

        $this->actingAs($op, 'sanctum')->postJson('/api/entries', [
            'date' => now('UTC')->toDateString(), 'type' => 'INGRESO',
            'items' => [['serviceId' => $this->ingId, 'quantity' => 3]],
        ])->assertCreated();

        $this->actingAs($op, 'sanctum')->getJson('/api/entries/me')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($op, 'sanctum')->getJson('/api/entries')->assertStatus(403);
        $this->actingAs($jefe, 'sanctum')->getJson('/api/entries')
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
