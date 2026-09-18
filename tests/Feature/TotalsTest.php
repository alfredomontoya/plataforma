<?php

namespace Tests\Feature;

use App\Models\DailyTotal;
use App\Models\Service;
use App\Models\User;
use App\Services\UserService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $username, string $role): User
    {
        return app(UserService::class)->createWithPosition([
            'username' => $username, 'password' => 'password', 'role' => $role,
            'title' => 'LIC', 'firstName' => 'N', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
    }

    private function makeService(string $name, int $sort = 0): Service
    {
        return Service::create([
            'name' => $name, 'type' => Service::TYPE_INGRESO, 'isActive' => true, 'sortOrder' => $sort,
        ]);
    }

    private function seedDay(string $day, array $qtyByName, ?User $op = null): void
    {
        $op ??= User::where('username', 'ing1')->first() ?? $this->makeUser('ing1', 'OPERATOR_INGRESO');
        foreach ($qtyByName as $name => $qty) {
            $service = Service::where('name', $name)->firstOrFail();
            DailyTotal::create([
                'userId' => $op->id,
                'serviceId' => $service->id,
                'date' => Carbon::parse($day, 'UTC'),
                'quantity' => $qty,
            ]);
        }
    }

    public function test_catalog_y_store_con_upsert(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $s1 = $this->makeService('TRANSFERENCIA NORMAL', 1);
        $s2 = $this->makeService('BAJA VEHICULO', 2);
        $auth = fn () => $this->actingAs($op, 'sanctum');
        $today = now('UTC')->toDateString();

        $auth()->getJson('/api/totals/catalog')->assertOk()->assertJsonCount(2, 'data');

        $payload = [
            'date' => $today,
            'items' => [
                ['serviceId' => $s1->id, 'quantity' => 230],
                ['serviceId' => $s2->id, 'quantity' => 5],
            ],
        ];
        $auth()->postJson('/api/totals', $payload)->assertCreated();
        // Reenvío pisa (upsert usuario×servicio×día).
        $payload['items'][0]['quantity'] = 231;
        $auth()->postJson('/api/totals', $payload)->assertCreated();
        $this->assertDatabaseCount('daily_totals', 2);

        $me = $auth()->getJson("/api/totals/me?date={$today}")->assertOk();
        $this->assertCount(2, $me->json('data'));
        $this->assertEquals('TRANSFERENCIA NORMAL', $me->json('data.0.service.name'));

        // Servicio inexistente o no-ingreso se rechaza (la app responde 400).
        $auth()->postJson('/api/totals', ['date' => $today, 'items' => [['serviceId' => '000000000000000000000000', 'quantity' => 1]]])
            ->assertStatus(400);
        $auth()->postJson('/api/totals', ['date' => $today, 'items' => [['serviceId' => $s2->id, 'quantity' => -1]]])
            ->assertStatus(400);
    }

    public function test_resolve_registra_faltantes(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $this->makeService('TRANSFERENCIA NORMAL', 1);
        $auth = fn () => $this->actingAs($op, 'sanctum');

        $res = $auth()->postJson('/api/totals/resolve', ['names' => ['TRANSFERENCIA NORMAL', '  TRAMITE NUEVO XYZ  ']])
            ->assertOk();
        $resolved = collect($res->json('data.resolved'))->keyBy('name');
        $this->assertFalse($resolved['TRANSFERENCIA NORMAL']['created']);
        $this->assertTrue($resolved['TRAMITE NUEVO XYZ']['created']);
        $this->assertDatabaseHas('services', [
            'name' => 'TRAMITE NUEVO XYZ', 'type' => 'INGRESO', 'isActive' => true,
            'codigo' => 'TNX', 'abreviation' => 'TRA. NUE. XYZ.',
        ]);
        $this->assertCount(2, $res->json('data.catalog'));

        // Coincidencia de código: agrega 2, 3, ...
        Service::create(['name' => 'OCUPADO TOTAL X', 'codigo' => 'OTX', 'type' => 'INGRESO', 'isActive' => true]);
        $auth()->postJson('/api/totals/resolve', ['names' => ['OTRA TREMENDA X']])->assertOk();
        $this->assertEquals('OTX2', Service::where('name', 'OTRA TREMENDA X')->value('codigo'));
        $this->assertEquals('OTR. TRE. X.', Service::where('name', 'OTRA TREMENDA X')->value('abreviation'));

        // Reenvío no duplica (insensible a espacios/caso).
        $again = $auth()->postJson('/api/totals/resolve', ['names' => ['tramite nuevo xyz']])->assertOk();
        $this->assertFalse($again->json('data.resolved.0.created'));
        $this->assertEquals(4, Service::where('type', 'INGRESO')->count());

        // Ya registrado, el store lo acepta por serviceId.
        $createdId = $res->json('data.resolved.1.serviceId');
        $auth()->postJson('/api/totals', [
            'date' => now('UTC')->toDateString(),
            'items' => [['serviceId' => $createdId, 'quantity' => 7]],
        ])->assertCreated();
    }

    public function test_roles_store_y_lectura(): void
    {
        $svc = $this->makeService('TRANSFERENCIA NORMAL', 1);
        $today = now('UTC')->toDateString();
        $payload = ['date' => $today, 'items' => [['serviceId' => $svc->id, 'quantity' => 5]]];

        $this->makeUser('ent1', 'OPERATOR_ENTREGA');
        $this->actingAs(User::where('username', 'ent1')->first(), 'sanctum')
            ->postJson('/api/totals', $payload)->assertForbidden();
        $this->actingAs(User::where('username', 'ent1')->first(), 'sanctum')
            ->postJson('/api/totals/resolve', ['names' => ['X']])->assertForbidden();

        $this->makeUser('jefe1', 'JEFE');
        $this->actingAs(User::where('username', 'jefe1')->first(), 'sanctum')
            ->getJson("/api/totals/summary/day?date={$today}")->assertOk();
    }

    public function test_operators_solo_ingreso_y_admin(): void
    {
        $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $this->makeUser('ent1', 'OPERATOR_ENTREGA');

        $op = User::where('username', 'ing1')->first();
        $res = $this->actingAs($op, 'sanctum')->getJson('/api/totals/operators')->assertOk();
        $this->assertEquals(['ing1'], array_column($res->json('data'), 'username'));

        $ent = User::where('username', 'ent1')->first();
        $this->actingAs($ent, 'sanctum')->getJson('/api/totals/operators')->assertForbidden();
    }

    public function test_summaries_dia_semana_tendencia(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $this->makeService('TRANSFERENCIA NORMAL', 1);
        $this->makeService('BAJA VEHICULO', 2);
        Service::where('name', 'TRANSFERENCIA NORMAL')->update(['abreviation' => 'TN']);
        $auth = fn () => $this->actingAs($jefe, 'sanctum');
        $today = now('UTC')->toDateString();
        $yesterday = now('UTC')->subDay()->toDateString();
        $this->seedDay($today, ['TRANSFERENCIA NORMAL' => 230, 'BAJA VEHICULO' => 29]);
        $this->seedDay($yesterday, ['TRANSFERENCIA NORMAL' => 20, 'BAJA VEHICULO' => 10]);

        $day = $auth()->getJson("/api/totals/summary/day?date={$today}")->assertOk();
        $this->assertEquals(259, $day->json('data.total'));
        // Operadores = registrados activos (ing1); JEFE e inactivos no cuentan.
        $this->assertEquals(1, $day->json('data.operators'));
        $off = $this->makeUser('ingOff', 'OPERATOR_INGRESO');
        $off->update(['isActive' => false]);
        $this->assertEquals(1, $auth()->getJson("/api/totals/summary/day?date={$today}")->json('data.operators'));
        $this->assertCount(2, $day->json('data.byTramite'));
        $this->assertEquals(1, $day->json('data.byTramite.0.nro'));
        $this->assertIsInt($day->json('data.byTramite.0.total'));
        $this->assertEquals('TN', $day->json('data.byTramite.0.abreviation'));
        Service::where('name', 'TRANSFERENCIA NORMAL')->update(['codigo' => 'TNOR']);
        $this->assertEquals('TNOR', $auth()->getJson("/api/totals/summary/day?date={$today}")->json('data.byTramite.0.codigo'));

        $monday = now('UTC')->startOfWeek(Carbon::MONDAY)->toDateString();
        $week = $auth()->getJson("/api/totals/summary/week?weekStart={$monday}")->assertOk();
        $this->assertGreaterThanOrEqual(289, $week->json('data.total'));

        $daily = $auth()->getJson("/api/totals/summary/daily?from={$yesterday}&to={$today}")->assertOk();
        $this->assertCount(2, $daily->json('data'));
        $this->assertEquals(30, $daily->json('data.0.total'));
        $this->assertEquals(259, $daily->json('data.1.total'));

        // Rango inválido y tope 93 días.
        $auth()->getJson("/api/totals/summary/daily?from={$today}&to={$yesterday}")->assertStatus(400);
        $from = now('UTC')->subDays(100)->toDateString();
        $auth()->getJson("/api/totals/summary/range?from={$from}&to={$today}")->assertStatus(400);
    }
}
