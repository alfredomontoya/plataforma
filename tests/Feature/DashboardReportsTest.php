<?php

namespace Tests\Feature;

use App\Models\Entry;
use App\Models\Service;
use App\Models\User;
use App\Services\UserService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
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
        $opI = User::where('username', 'ing1')->first()
            ?? app(UserService::class)->createWithPosition([
                'username' => 'ing1', 'password' => 'password', 'role' => 'OPERATOR_INGRESO',
                'title' => 'LIC', 'firstName' => 'I', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
            ]);
        $opE = User::where('username', 'ent1')->first()
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
        $monday = now('UTC')->startOfWeek(Carbon::MONDAY)->toDateString();
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

    public function test_reporte_incrusta_tres_graficos(): void
    {
        $jefe = $this->jefe();
        $today = now('UTC')->toDateString();
        $this->seedDay($today, 7, 3);

        $gen = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'DAY', 'date' => $today,
            'nroCI' => 'INF-002', 'dirigidoA' => 'Director', 'puestoDirigidoA' => 'Dirección',
        ]);
        $gen->assertCreated();

        $path = $gen->json('data.filePath');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $pngs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/') && str_ends_with($name, '.png')) {
                $pngs[] = $name;
            }
        }
        // Dos tortas (ingreso/entrega) + tendencia diaria del mes.
        $this->assertCount(3, $pngs, 'Deben incrustarse 3 gráficos PNG: '.implode(',', $pngs));
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertStringNotContainsString('GRAFICO', $xml);
    }

    /** PNG de color plano para verificar que se incrusta exactamente el enviado. */
    private function tinyPng(int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(2, 2);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** Plantilla explícita estilo anterior (filas legacy + 3 gráficos viejos). */
    private function oldStyleTemplate(User $jefe): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $pw = new PhpWord;
        $section = $pw->addSection();
        $section->addText('Informe ${NRO_CI}');
        foreach ([['${ING_NOMBRE}', '${ING_TOTAL}'], ['${ENT_NOMBRE}', '${ENT_TOTAL}']] as [$a, $b]) {
            $table = $section->addTable();
            $table->addRow();
            $table->addCell()->addText($a);
            $table->addCell()->addText($b);
        }
        $section->addText('${GRAFICO_INGRESO}');
        $section->addText('${GRAFICO_ENTREGA}');
        $section->addText('${GRAFICO_TENDENCIA}');
        (new Word2007($pw))->save($path);

        return $this->actingAs($jefe, 'sanctum')->post('/api/templates', [
            'name' => 'Vieja',
            'file' => new UploadedFile($path, 'vieja.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ])->assertCreated()->json('data.id');
    }

    public function test_generate_rango_incrusta_graficos_del_dashboard(): void
    {
        $jefe = $this->jefe();
        $from = now('UTC')->subDays(2)->toDateString();
        $to = now('UTC')->toDateString();
        $this->seedDay($from, 4, 1);
        $this->seedDay($to, 6, 2);
        $templateId = $this->oldStyleTemplate($jefe);

        $png = $this->tinyPng(255, 0, 0);
        $b64 = base64_encode($png);

        $gen = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'RANGE', 'from' => $from, 'to' => $to,
            'templateId' => $templateId,
            'nroCI' => 'INF-RANGE', 'dirigidoA' => 'Director', 'puestoDirigidoA' => 'Dirección',
            'charts' => [
                'grafico_ingreso' => 'data:image/png;base64,'.$b64,
                'grafico_entrega' => $b64,
                'grafico_tendencia' => 'data:image/png;base64,'.$b64,
            ],
        ]);
        $gen->assertCreated()->assertJsonPath('data.mode', 'RANGE');
        $this->assertEquals(10, $gen->json('data.totalIngreso'));
        $this->assertEquals(3, $gen->json('data.totalEntrega'));

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($gen->json('data.filePath')) === true);
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/') && str_ends_with($name, '.png')) {
                $media[] = $zip->getFromName($name);
            }
        }
        $zip->close();
        $this->assertCount(3, $media, 'Deben incrustarse los 3 gráficos enviados por el dashboard.');
        foreach ($media as $bytes) {
            $this->assertSame($png, $bytes, 'El PNG incrustado debe ser exactamente el enviado.');
        }
    }

    public function test_generate_rango_requiere_fechas(): void
    {
        $jefe = $this->jefe();
        $res = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'RANGE', 'nroCI' => 'INF-X', 'dirigidoA' => 'Director', 'puestoDirigidoA' => 'Dirección',
        ]);
        $res->assertStatus(400)->assertJson(['success' => false]);
        $this->assertArrayHasKey('from', $res->json('details'));
        $this->assertArrayHasKey('to', $res->json('details'));
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_generate_grafico_adjunto_invalido(): void
    {
        $jefe = $this->jefe();
        $today = now('UTC')->toDateString();
        $this->seedDay($today, 1, 1);

        $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'DAY', 'date' => $today,
            'nroCI' => 'INF-BAD', 'dirigidoA' => 'Director', 'puestoDirigidoA' => 'Dirección',
            'charts' => ['grafico_ingreso' => 'esto-no-es-un-png'],
        ])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_templates_crud_y_default(): void
    {
        $jefe = $this->jefe();
        $auth = fn () => $this->actingAs($jefe, 'sanctum');

        $auth()->getJson('/api/templates/default')->assertStatus(404);

        $doc = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $pw = new PhpWord;
        $pw->addSection()->addText('hola ${FECHA}');
        (new Word2007($pw))->save($doc);

        $up = $auth()->post('/api/templates', [
            'name' => 'Base', 'file' => new UploadedFile($doc, 'base.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ]);
        $up->assertCreated();
        $this->assertTrue($up->json('data.isDefault'));

        $auth()->getJson('/api/templates/default')->assertOk();
        $auth()->deleteJson('/api/templates/'.$up->json('data.id'))->assertOk();
        @unlink($doc);
    }
}
