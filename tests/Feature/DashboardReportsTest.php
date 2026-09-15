<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Service;
use App\Services\UserService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function seedDay(string $day, int $ingQty, int $entQty): void
    {
        $opI = \App\Models\User::where('username', 'ing1')->first()
            ?? app(UserService::class)->createWithPosition([
                'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
                'title' => 'LIC', 'firstName' => 'I', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
            ]);
        $opE = \App\Models\User::where('username', 'ent1')->first()
            ?? app(UserService::class)->createWithPosition([
                'username' => 'ent1', 'password' => 'password', 'role' => 'OPERATOR_ENTREGA',
                'title' => 'LIC', 'firstName' => 'E', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
            ]);
        $ing = Service::firstOrCreate(['name' => 'SERV ING'], ['abreviation' => 'SI', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1]);
        $ent = Service::firstOrCreate(['name' => 'SERV ENT'], ['abreviation' => 'SE', 'type' => 'ENTREGA', 'isActive' => true, 'sortOrder' => 1]);

        Entry::create(['userId' => $opI->id, 'serviceId' => $ing->id, 'date' => Carbon::parse($day, 'UTC'), 'type' => 'INGRESO', 'quantity' => $ingQty]);
        Entry::create(['userId' => $opE->id, 'serviceId' => $ent->id, 'date' => Carbon::parse($day, 'UTC'), 'type' => 'ENTREGA', 'quantity' => $entQty]);
    }

    private function jefe()
    {
        return app(UserService::class)->createWithPosition([
            'username' => 'jefe1', 'password' => 'password', 'role' => 'JEFE',
            'title' => 'LIC', 'firstName' => 'J', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
    }

    public function test_dashboard_day_con_previo_y_trend(): void
    {
        $jefe = $this->jefe();
        $today = now('UTC')->toDateString();
        $yesterday = now('UTC')->subDay()->toDateString();
        $this->seedDay($today, 10, 4);
        $this->seedDay($yesterday, 5, 4);

        $res = $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/summary?date={$today}");
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertEquals(10, $res->json('data.totalIngreso'));
        $this->assertEquals(4, $res->json('data.totalEntrega'));
        $this->assertCount(2, $res->json('data.operators'));
        $this->assertEquals('SI', $res->json('data.ingreso.0.abreviation'));
        // Contrato: total numérico (MySQL devuelve SUM() como string).
        $this->assertIsInt($res->json('data.ingreso.0.total'));
    }

    public function test_dashboard_daily_devuelve_totales_por_dia(): void
    {
        $jefe = $this->jefe();
        $today = now('UTC')->toDateString();
        $yesterday = now('UTC')->subDay()->toDateString();
        $this->seedDay($today, 10, 4);
        $this->seedDay($yesterday, 5, 2);

        $res = $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/daily?from={$yesterday}&to={$today}");
        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertEquals($yesterday, $res->json('data.0.date'));
        $this->assertEquals(5, $res->json('data.0.ingreso'));
        $this->assertEquals(2, $res->json('data.0.entrega'));
        $this->assertEquals(10, $res->json('data.1.ingreso'));
        $this->assertIsInt($res->json('data.1.ingreso'));
        // rango inválido (fin antes que inicio)
        $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/daily?from={$today}&to={$yesterday}")->assertStatus(400);
    }

    public function test_dashboard_weekly_y_range_con_tope(): void
    {
        $jefe = $this->jefe();
        $monday = now('UTC')->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
        $this->seedDay($monday, 6, 2);

        $w = $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/weekly?weekStart={$monday}");
        $w->assertOk();
        $this->assertEquals(6, $w->json('data.totalIngreso'));
        // Desglose por servicio también en semana (tortas visibles fuera del día).
        $this->assertEquals('SI', $w->json('data.ingresoByService.0.abreviation'));
        $this->assertEquals(6, $w->json('data.ingresoByService.0.total'));

        $from = now('UTC')->subDays(100)->toDateString();
        $to = now('UTC')->toDateString();
        $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/range?from={$from}&to={$to}")
            ->assertStatus(400)->assertJson(['success' => false]);

        $ok = $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/range?from={$monday}&to={$monday}");
        $ok->assertOk()->assertJsonPath('data.totalIngreso', 6);
    }

    public function test_generate_preview_historial_download(): void
    {
        $jefe = $this->jefe();
        $today = now('UTC')->toDateString();
        $this->seedDay($today, 7, 3);
        $auth = fn () => $this->actingAs($jefe, 'sanctum');

        $payload = [
            'mode' => 'DAY', 'date' => $today,
            'nroCI' => 'INF-001', 'dirigidoA' => 'Director', 'puestoDirigidoA' => 'Dirección',
        ];

        // preview no guarda
        $prev = $auth()->postJson('/api/reports/preview', $payload);
        $prev->assertOk();
        $this->assertStringContainsString('officedocument', $prev->headers->get('Content-Type'));
        $this->assertDatabaseCount('reports', 0);

        // generate guarda + fallback crea plantilla default
        $gen = $auth()->postJson('/api/reports/generate', $payload);
        $gen->assertCreated()->assertJson(['success' => true]);
        $id = $gen->json('data.id');
        $this->assertNotEmpty($gen->json('data.templateId'));
        $this->assertTrue(file_exists($gen->json('data.filePath')));
        $this->assertDatabaseHas('templates', ['isDefault' => true]);

        // historial + download + delete
        $auth()->getJson('/api/reports?search=INF-001')->assertOk()->assertJsonCount(1, 'data');
        $auth()->getJson("/api/reports/{$id}/download")->assertOk();
        $auth()->deleteJson("/api/reports/{$id}")->assertOk();
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_templates_crud_y_default(): void
    {
        $jefe = $this->jefe();
        $auth = fn () => $this->actingAs($jefe, 'sanctum');

        $auth()->getJson('/api/templates/default')->assertStatus(404);

        $doc = tempnam(sys_get_temp_dir(), 'tpl') . '.docx';
        $pw = new \PhpOffice\PhpWord\PhpWord();
        $pw->addSection()->addText('hola ${FECHA}');
        (new \PhpOffice\PhpWord\Writer\Word2007($pw))->save($doc);

        $up = $auth()->post('/api/templates', [
            'name' => 'Base', 'file' => new \Illuminate\Http\UploadedFile($doc, 'base.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ]);
        $up->assertCreated();
        $this->assertTrue($up->json('data.isDefault'));

        $auth()->getJson('/api/templates/default')->assertOk();
        $auth()->deleteJson('/api/templates/' . $up->json('data.id'))->assertOk();
        @unlink($doc);
    }
}
