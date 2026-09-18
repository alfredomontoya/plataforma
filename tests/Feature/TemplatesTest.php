<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Writer\Word2007;
use Tests\TestCase;

class TemplatesTest extends TestCase
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

    private function docx(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $pw = new PhpWord;
        $section = $pw->addSection();
        foreach ($lines as $line) {
            $section->addText($line);
        }
        // Estructura mínima estilo institucional {VAR}: filas clonables + gráficos.
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('{ING_NOMBRE}');
        $table->addCell()->addText('{ING_TOTAL}');
        $table2 = $section->addTable();
        $table2->addRow();
        $table2->addCell()->addText('{ENT_NOMBRE}');
        $table2->addCell()->addText('{ENT_TOTAL}');
        $section->addText('{GRAFICO_INGRESO}');
        $section->addText('{GRAFICO_ENTREGA}');
        $section->addText('{GRAFICO_TENDENCIA}');
        (new Word2007($pw))->save($path);

        return $path;
    }

    private function upload(User $user, string $path, string $name = 'Base'): array
    {
        $res = $this->actingAs($user, 'sanctum')->post('/api/templates', [
            'name' => $name,
            'file' => new UploadedFile($path, 'base.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
        ])->assertCreated();

        return $res->json('data');
    }

    public function test_inspect_paragraphs_edit_y_duplicate(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $auth = fn () => $this->actingAs($jefe, 'sanctum');
        $doc = $this->docx(['Titulo Fijo', 'hola {FECHA}', '{COSA_RARA}']);
        $tpl = $this->upload($jefe, $doc);

        $ins = $auth()->getJson("/api/templates/{$tpl['id']}/inspect")->assertOk()->json('data');
        $this->assertContains('FECHA', $ins['groups']['texto']['found']);
        $this->assertContains('TABLA_INGRESO', $ins['groups']['tablas']['missing']);
        $this->assertNotEmpty($ins['unknown']);

        $pars = $auth()->getJson("/api/templates/{$tpl['id']}/paragraphs")->assertOk()->json('data');
        $this->assertNotEmpty($pars);
        // Párrafos solo-macro se listan bloqueados (no editables).
        $locked = array_values(array_filter($pars, fn ($p) => $p['locked']));
        $this->assertNotEmpty($locked);
        $idx = null;
        foreach ($pars as $p) {
            if (str_contains($p['text'], 'Titulo Fijo')) {
                $idx = $p['index'];
            }
        }
        $this->assertNotNull($idx);

        // Llaves rechazadas.
        $auth()->patchJson("/api/templates/{$tpl['id']}/paragraphs", ['texts' => [$idx => 'mal {VAR}']])
            ->assertStatus(400);

        $auth()->patchJson("/api/templates/{$tpl['id']}/paragraphs", ['texts' => [$idx => 'Titulo Editado']])
            ->assertOk();
        $pars2 = $auth()->getJson("/api/templates/{$tpl['id']}/paragraphs")->assertOk()->json('data');
        $texts = array_column($pars2, 'text', 'index');
        $this->assertEquals('Titulo Editado', $texts[$idx]);

        // El informe se genera con una plantilla limpia editada.
        $doc2 = $this->docx(['Titulo Base', 'hola {FECHA}']);
        $tpl2 = $this->upload($jefe, $doc2, 'Limpia');
        $parsClean = $auth()->getJson("/api/templates/{$tpl2['id']}/paragraphs")->assertOk()->json('data');
        $idxClean = null;
        foreach ($parsClean as $p) {
            if (str_contains($p['text'], 'Titulo Base')) {
                $idxClean = $p['index'];
            }
        }
        $this->assertNotNull($idxClean);
        $auth()->patchJson("/api/templates/{$tpl2['id']}/paragraphs", ['texts' => [$idxClean => 'Titulo Editado']])->assertOk();
        $gen = $auth()->postJson('/api/reports/generate', [
            'mode' => 'DAY', 'date' => now('UTC')->toDateString(),
            'templateId' => $tpl2['id'],
            'nroCI' => 'INF-TPL', 'dirigidoA' => 'D', 'puestoDirigidoA' => 'P',
        ])->assertCreated();
        $this->assertStringContainsString('Titulo Editado', $this->docxText($gen->json('data.filePath')));

        $dup = $auth()->post("/api/templates/{$tpl['id']}/duplicate")->assertCreated()->json('data');
        $this->assertStringContainsString('(copia)', $dup['name']);
        $this->assertFalse((bool) $dup['isDefault']);

        $auth()->getJson("/api/templates/{$tpl['id']}/download")->assertOk();
        @unlink($doc);
        @unlink($doc2);
    }

    public function test_editar_con_marcas_y_agregar_parrafo(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $auth = fn () => $this->actingAs($jefe, 'sanctum');
        $doc = $this->docx(['Titulo Base', 'hola {FECHA}']);
        $tpl = $this->upload($jefe, $doc, 'Editable');

        // Las marcas del motor se listan bloqueadas.
        $pars = $auth()->getJson("/api/templates/{$tpl['id']}/paragraphs")->assertOk()->json('data');
        $locked = array_values(array_filter($pars, fn ($p) => $p['locked']));
        $this->assertNotEmpty($locked);

        // Editar con marcador conocido OK; desconocido 400.
        $idx = null;
        foreach ($pars as $p) {
            if (! $p['locked'] && str_contains($p['text'], 'Titulo Base')) {
                $idx = $p['index'];
            }
        }
        $this->assertNotNull($idx);
        $auth()->patchJson("/api/templates/{$tpl['id']}/paragraphs", ['texts' => [$idx => 'Informe {NRO_CI}']])->assertOk();
        $auth()->patchJson("/api/templates/{$tpl['id']}/paragraphs", ['texts' => [$idx => 'Mal {COSA_RARA}']])->assertStatus(400);

        // Agregar párrafo con marca: aparece en inspector y en el informe.
        $pars2 = $auth()->postJson("/api/templates/{$tpl['id']}/paragraphs", ['text' => 'Elaborado por {REMITENTE}'])
            ->assertCreated()->json('data');
        $this->assertNotEmpty(array_filter($pars2, fn ($p) => str_contains($p['text'], 'Elaborado por')));
        $ins = $auth()->getJson("/api/templates/{$tpl['id']}/inspect")->assertOk()->json('data');
        $this->assertContains('REMITENTE', $ins['groups']['texto']['found']);

        $gen = $auth()->postJson('/api/reports/generate', [
            'mode' => 'DAY', 'date' => now('UTC')->toDateString(),
            'templateId' => $tpl['id'],
            'nroCI' => 'INF-EDIT', 'dirigidoA' => 'D', 'puestoDirigidoA' => 'P',
        ])->assertCreated();
        $xml = $this->docxText($gen->json('data.filePath'));
        $this->assertStringContainsString('Informe INF-EDIT', $xml);
        $this->assertStringContainsString('Elaborado por', $xml);
        @unlink($doc);
    }

    public function test_templates_solo_admin_jefe(): void
    {
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $doc = $this->docx(['hola']);
        $tpl = $this->upload($jefe, $doc);

        $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $op = \App\Models\User::where('username', 'ing1')->first();
        $this->actingAs($op, 'sanctum')->getJson("/api/templates/{$tpl['id']}/inspect")->assertForbidden();
        $this->actingAs($op, 'sanctum')->getJson("/api/templates/{$tpl['id']}/paragraphs")->assertForbidden();
        @unlink($doc);
    }

    private function docxText(string $path): string
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return strip_tags($xml);
    }
}
