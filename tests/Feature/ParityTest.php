<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Service;
use App\Services\UserService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function jefe()
    {
        return app(UserService::class)->createWithPosition([
            'username' => 'jefe1', 'password' => 'password', 'role' => 'JEFE',
            'title' => 'LIC', 'firstName' => 'J', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
    }

    public function test_rutas_web_renderizan(): void
    {
        foreach (['/login', '/operador/entrada', '/admin/usuarios', '/admin/servicios',
                     '/jefe/dashboard', '/jefe/reportes', '/jefe/reportes/historial'] as $uri) {
            $this->get($uri)->assertOk();
        }
        $this->get('/')->assertRedirect('/operador/entrada');
    }

    public function test_historial_filtra_por_dia_bolivia(): void
    {
        $jefe = $this->jefe();
        $auth = fn () => $this->actingAs($jefe, 'sanctum');
        $svc = Service::create(['name' => 'S', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1]);
        Entry::create(['userId' => $jefe->id, 'serviceId' => $svc->id,
            'date' => now('UTC')->startOfDay(), 'type' => 'INGRESO', 'quantity' => 1]);

        $payload = ['mode' => 'DAY', 'date' => now('UTC')->toDateString(),
            'nroCI' => 'UTC4-1', 'dirigidoA' => 'D', 'puestoDirigidoA' => 'P'];
        $auth()->postJson('/api/reports/generate', $payload)->assertCreated();

        $lapazToday = now('America/La_Paz')->toDateString();
        $lapazYesterday = now('America/La_Paz')->subDay()->toDateString();
        $auth()->getJson("/api/reports?date={$lapazToday}")->assertOk()->assertJsonCount(1, 'data');
        $auth()->getJson("/api/reports?date={$lapazYesterday}")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_docx_es_zip_valido_con_datos_y_dos_imagenes(): void
    {
        $jefe = $this->jefe();
        $svc = Service::create(['name' => 'SERV DOCX', 'abreviation' => 'SD', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1]);
        Entry::create(['userId' => $jefe->id, 'serviceId' => $svc->id,
            'date' => now('UTC')->startOfDay(), 'type' => 'INGRESO', 'quantity' => 8]);

        $gen = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'DAY', 'date' => now('UTC')->toDateString(),
            'nroCI' => 'DOCX-9', 'dirigidoA' => 'Dirección General', 'puestoDirigidoA' => 'Director',
        ])->assertCreated();

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($gen->json('data.filePath')) === true);
        $xml = $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('DOCX-9', $xml);
        $this->assertStringContainsString('Dirección General', $xml);
        $this->assertStringContainsString('SERV DOCX', $xml);
        $images = array_values(array_filter(
            array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)),
            // Solo las tortas generadas (.png); la plantilla puede traer sus imágenes.
            fn ($n) => str_starts_with((string) $n, 'word/media/') && str_ends_with((string) $n, '.png')
        ));
        $this->assertCount(2, $images);
        $zip->close();

        // semana lunes–domingo: el periodo arranca en lunes
        $monday = Carbon::now('UTC')->startOfWeek(Carbon::MONDAY)->toDateString();
        $w = $this->actingAs($jefe, 'sanctum')->getJson("/api/reports/dashboard/weekly?weekStart={$monday}");
        $w->assertOk()->assertJsonPath('data.periodStart', $monday);
    }
}
