<?php

namespace Tests\Feature;

use App\Models\DailyTotal;
use App\Models\Service;
use App\Models\User;
use App\Services\TemplateInspector;
use App\Services\UserService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use Tests\TestCase;

class TotalsReportTest extends TestCase
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

    private function seedWeekTotals(): string
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $s1 = Service::create(['name' => 'TRANSFERENCIA NORMAL', 'abreviation' => 'TN', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 1]);
        $s2 = Service::create(['name' => 'BAJA VEHICULO', 'abreviation' => 'BV', 'type' => 'INGRESO', 'isActive' => true, 'sortOrder' => 2]);
        $monday = Carbon::now('UTC')->startOfWeek(Carbon::MONDAY);
        $cursor = $monday->copy();
        $today = Carbon::now('UTC')->startOfDay();
        while ($cursor->lte($today)) {
            DailyTotal::create(['userId' => $op->id, 'serviceId' => $s1->id, 'date' => $cursor->copy(), 'quantity' => 10]);
            DailyTotal::create(['userId' => $op->id, 'serviceId' => $s2->id, 'date' => $cursor->copy(), 'quantity' => 5]);
            $cursor->addDay();
        }

        return $monday->toDateString();
    }

    private function totalsTemplate(array $extraLines = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $pw = new PhpWord;
        $section = $pw->addSection();
        $section->addText('Informe {NRO_CI} del {FECHA}');
        foreach ($extraLines as $line) {
            $section->addText($line);
        }
        $section->addText('{TABLA_INGRESO}');
        $section->addText('{GRAFICO_DISTRIBUCION_TRAMITE}');
        $section->addText('{GRAFICO_TOTAL_TRAMITE}');
        $section->addText('{GRAFICO_TENDENCIA_DIA}');
        (new Word2007($pw))->save($path);

        return $path;
    }

    private function upload(User $user, string $path): array
    {
        return $this->actingAs($user, 'sanctum')->post('/api/templates', [
            'name' => 'Totales',
            'file' => new UploadedFile($path, 'totales.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ])->assertCreated()->json('data');
    }

    private function docxParts(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $pngs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'word/media/') && str_ends_with($name, '.png')) {
                $pngs[] = $name;
            }
        }
        $zip->close();

        return [$xml, $pngs];
    }

    public function test_reporte_totales_con_fallback_gd(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $monday = $this->seedWeekTotals();
        $doc = $this->totalsTemplate();
        $tpl = $this->upload($jefe, $doc);

        $gen = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'WEEK', 'weekStart' => $monday,
            'templateId' => $tpl['id'],
            'nroCI' => 'INF-TOT', 'dirigidoA' => 'D', 'puestoDirigidoA' => 'P',
        ])->assertCreated();

        [$xml, $pngs] = $this->docxParts($gen->json('data.filePath'));
        $this->assertStringNotContainsString('GRAFICO_', $xml);
        $this->assertStringNotContainsString('TABLA_INGRESO', $xml);
        $this->assertStringContainsString('TRÁMITE', $xml);
        $this->assertStringContainsString('TRANSFERENCIA NORMAL', $xml);
        // Títulos con fechas como el dashboard + total en tendencia y tabla.
        $this->assertStringContainsString('Distribución por trámite ·', $xml);
        $this->assertStringContainsString('Total por trámite ·', $xml);
        $this->assertStringContainsString('Tendencia día a día – Total ·', $xml);
        $days = (int) Carbon::parse($monday, 'UTC')->diffInDays(now('UTC')->startOfDay()) + 1;
        $expectedTotal = $days * 15;
        $this->assertStringContainsString("Total: {$expectedTotal}", $xml);
        $this->assertGreaterThanOrEqual(3, count($pngs), 'Torta + barras + tendencia: '.implode(',', $pngs));

        // Alta resolución: cada PNG de al menos 1100 px de ancho.
        $zip = new \ZipArchive;
        $zip->open($gen->json('data.filePath'));
        $widths = [];
        foreach ($pngs as $name) {
            $size = getimagesizefromstring((string) $zip->getFromName($name));
            $widths[$name] = $size[0];
            $this->assertGreaterThanOrEqual(1100, $size[0], $name);
        }
        $zip->close();
        @unlink($doc);
    }

    public function test_reporte_totales_con_png_del_dashboard(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $monday = $this->seedWeekTotals();
        $doc = $this->totalsTemplate();
        $tpl = $this->upload($jefe, $doc);

        $img = imagecreatetruecolor(4, 4);
        imagefill($img, 0, 0, imagecolorallocate($img, 147, 51, 234));
        ob_start();
        imagepng($img);
        $b64 = base64_encode((string) ob_get_clean());
        imagedestroy($img);

        $gen = $this->actingAs($jefe, 'sanctum')->postJson('/api/reports/generate', [
            'mode' => 'WEEK', 'weekStart' => $monday,
            'templateId' => $tpl['id'],
            'nroCI' => 'INF-TOT2', 'dirigidoA' => 'D', 'puestoDirigidoA' => 'P',
            'charts' => [
                'grafico_distribucion' => 'data:image/png;base64,'.$b64,
                'grafico_barras' => $b64,
                'grafico_tendencia_dia' => 'data:image/png;base64,'.$b64,
            ],
        ])->assertCreated();

        [$xml, $pngs] = $this->docxParts($gen->json('data.filePath'));
        $this->assertStringNotContainsString('GRAFICO_', $xml);
        $this->assertCount(3, $pngs);
        @unlink($doc);
    }

    public function test_plantilla3_inspect_sin_desconocidos(): void
    {
        $src = base_path('assets/templates/plantilla3.docx');
        $this->assertFileExists($src);
        $ins = app(TemplateInspector::class)->inspect($src);
        $this->assertSame([], $ins['unknown']);
        $this->assertContains('GRAFICO_DISTRIBUCION_TRAMITE', $ins['groups']['graficos']['found']);
        $this->assertContains('GRAFICO_TOTAL_TRAMITE', $ins['groups']['graficos']['found']);
        $this->assertContains('GRAFICO_TENDENCIA_DIA', $ins['groups']['graficos']['found']);
        $this->assertContains('TABLA_INGRESO', $ins['groups']['tablas']['found']);
    }
}
