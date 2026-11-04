<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\TramiteRegistro;
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
        return app(UserService::class)->create([
            'username' => $username, 'password' => 'password', 'role' => $role,
            'firstName' => 'N', 'lastName' => 'U',
        ]);
    }

    private function makeService(string $name, int $sort = 0): Service
    {
        return Service::create([
            'name' => $name, 'type' => Service::TYPE_INGRESO, 'isActive' => true, 'sortOrder' => $sort,
        ]);
    }

    /** Un registro = un trámite: crea N filas por servicio. */
    private function seedDay(string $day, array $qtyByName, ?User $op = null): void
    {
        $op ??= User::where('username', 'ing1')->first() ?? $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $n = 0;
        foreach ($qtyByName as $name => $qty) {
            $service = Service::where('name', $name)->firstOrFail();
            for ($i = 1; $i <= $qty; $i++) {
                $n++;
                TramiteRegistro::create([
                    'userId' => $op->id,
                    'serviceId' => $service->id,
                    'date' => Carbon::parse($day, 'UTC'),
                    'placa' => sprintf('T%06d', $n),
                    'activo' => true,
                ]);
            }
        }
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
        $this->assertEquals(230, $day->json('data.byTramite.0.total'));
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

    public function test_solo_admin_y_jefe_leen_los_resumenes(): void
    {
        $ing = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $today = now('UTC')->toDateString();

        $this->actingAs($ing, 'sanctum')
            ->getJson("/api/totals/summary/day?date={$today}")
            ->assertForbidden();
    }
}
