<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\TramiteRegistro;
use App\Models\User;
use App\Services\TramiteRegistroService;
use App\Services\TramiteReportService;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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
            'placa' => '123abc', 'ticket' => 'T1', 'observaciones' => 'primero',
        ])->assertCreated();
        $this->assertEquals('123ABC', $first->json('data.placa'));
        $this->assertTrue($first->json('data.activo'));

        $second = $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '999ZZZ', 'ticket' => 'T2',
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

    public function test_ticket_y_estado(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $admin = $this->makeUser('admin1', 'ADMIN');
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $today = now('UTC')->toDateString();

        // Ticket obligatorio para operadores.
        $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '123ABC',
        ])->assertStatus(422);

        // Ticket alfanumérico, normalizado a mayúsculas.
        $created = $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '123ABC',
            'ticket' => ' ab12cd ',
        ])->assertCreated();
        $this->assertSame('AB12CD', $created->json('data.ticket'));
        $this->assertSame('procesado', $created->json('data.estado'));

        // Ticket inválido (con caracteres no alfanuméricos).
        $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '124ABC',
            'ticket' => 'AB-12',
        ])->assertStatus(400);

        // Duplicado: solo advierte.
        $duplicated = $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '125ABC',
            'ticket' => 'AB12CD',
        ])->assertCreated();
        $this->assertNotNull($duplicated->json('data.advertencia'));

        // Estado explícito y validación.
        $received = $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '126ABC',
            'ticket' => 'AB12CE', 'estado' => 'recepcionado',
        ])->assertCreated();
        $this->assertSame('recepcionado', $received->json('data.estado'));

        $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '127ABC',
            'ticket' => 'AB12CF', 'estado' => 'otro',
        ])->assertStatus(400);

        // Ticket opcional para ADMIN y JEFE.
        $this->actingAs($admin, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '128ABC',
        ])->assertCreated()->assertJsonPath('data.ticket', null);

        $this->actingAs($jefe, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '129ABC',
        ])->assertCreated()->assertJsonPath('data.ticket', null);

        // Búsqueda por ticket y estado.
        $this->actingAs($jefe, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&search=AB12CD")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($jefe, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&search=recepcionado")
            ->assertOk()->assertJsonCount(1, 'data');
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
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => 'AB 123', 'ticket' => 'T3',
        ])->assertStatus(400);
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId,
        ])->assertStatus(400);

        // Ticket obligatorio para operadores.
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '123ABC',
        ])->assertStatus(422);

        // Fecha futura bloqueada.
        $auth()->postJson('/api/tramites', [
            'date' => $future, 'serviceId' => $this->ingId, 'placa' => '123ABC', 'ticket' => 'T4',
        ])->assertStatus(403);

        // Rol incorrecto para el tipo de trámite.
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->entId, 'placa' => '123ABC', 'ticket' => 'T5',
        ])->assertStatus(403);

        // Fecha pasada: permitida por defecto, bloqueada sin canBackfill.
        $auth()->postJson('/api/tramites', [
            'date' => $past, 'serviceId' => $this->ingId, 'placa' => '123PAS', 'ticket' => 'T6',
        ])->assertCreated();

        $op->update(['canBackfill' => false]);
        $auth()->postJson('/api/tramites', [
            'date' => $past, 'serviceId' => $this->ingId, 'placa' => '124PAS', 'ticket' => 'T7',
        ])->assertStatus(403);

        // Servicio inactivo.
        Service::whereKey($this->ingId)->update(['isActive' => false]);
        $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '123ABC', 'ticket' => 'T8',
        ])->assertStatus(400);
    }

    public function test_formatos_de_placa_y_poliza(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $auth = fn () => $this->actingAs($op, 'sanctum');
        $today = now('UTC')->toDateString();

        // Placa: 3 o 4 dígitos + 3 letras. Póliza: 5 a 10 dígitos.
        foreach (['123ABC', '1234ABC', '123abc', '12345', '123456789', '0123456789'] as $i => $placa) {
            $auth()->postJson('/api/tramites', [
                'date' => $today, 'serviceId' => $this->ingId, 'placa' => $placa, 'ticket' => 'F'.$i,
            ])->assertCreated();
        }

        $invalid = ['ABC123', '12ABC', '12345ABC', '123AB', '123ABCD', '1234', '12345678901', '123A4BC', '123-ABC', '123 ABC'];
        foreach ($invalid as $placa) {
            $auth()->postJson('/api/tramites', [
                'date' => $today, 'serviceId' => $this->ingId, 'placa' => $placa,
            ])->assertStatus(400);
        }

        // La edición aplica las mismas reglas.
        $row = $auth()->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '111AAA', 'ticket' => 'F9',
        ])->assertCreated();
        $id = $row->json('data.id');

        $auth()->patchJson("/api/tramites/{$id}", ['placa' => '987654'])->assertOk();
        $auth()->patchJson("/api/tramites/{$id}", ['placa' => '123AB'])->assertStatus(400);
    }

    public function test_rango_fechas_busqueda_y_alcance_por_rol(): void
    {
        $op1 = $this->makeUser('ing1', 'OPERATOR_INGRESO', ['firstName' => 'Ana', 'lastName' => 'Quispe']);
        $op2 = $this->makeUser('ing2', 'OPERATOR_INGRESO', ['firstName' => 'Pedro', 'lastName' => 'Ramirez']);
        $admin = $this->makeUser('admin1', 'ADMIN');
        $today = now('UTC')->startOfDay();
        $from = $today->copy()->subDays(2)->toDateString();
        $to = $today->copy()->subDays(1)->toDateString();

        $base = ['serviceId' => $this->ingId, 'activo' => true];
        TramiteRegistro::create($base + ['userId' => $op1->id, 'date' => $today->copy()->subDays(2), 'placa' => '111AAA', 'observaciones' => 'primera observacion']);
        TramiteRegistro::create($base + ['userId' => $op1->id, 'date' => $today->copy()->subDays(1), 'placa' => '222BBB', 'observaciones' => 'segunda observacion']);
        TramiteRegistro::create($base + ['userId' => $op2->id, 'date' => $today->copy()->subDays(1), 'placa' => '333CCC', 'observaciones' => 'otra observacion']);

        // Operador: solo sus registros dentro del rango.
        $own = $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertSame('222BBB', $own->json('data.0.placa'));

        // Admin: todos los usuarios.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        // Búsqueda por placa, observaciones, usuario y tipo de trámite.
        $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}&search=222BBB")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}&search=otra")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}&search=Pedro")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$from}&to={$to}&search=INSCRIPCION")
            ->assertOk()->assertJsonCount(3, 'data');

        // Rango inválido.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$to}&to={$from}")
            ->assertStatus(400);
    }

    public function test_paginacion_del_servidor_con_metadatos_y_alcance(): void
    {
        $op1 = $this->makeUser('ing1', 'OPERATOR_INGRESO', ['firstName' => 'Ana', 'lastName' => 'Quispe']);
        $op2 = $this->makeUser('ing2', 'OPERATOR_INGRESO', ['firstName' => 'Pedro', 'lastName' => 'Ramirez']);
        $admin = $this->makeUser('admin1', 'ADMIN');
        $day = now('UTC')->startOfDay();
        $today = $day->toDateString();

        for ($i = 1; $i <= 25; $i++) {
            TramiteRegistro::create([
                'userId' => $op1->id,
                'serviceId' => $this->ingId,
                'date' => $day,
                'placa' => sprintf('%03dAAA', $i),
                'observaciones' => $i === 3 ? 'buscar especial' : null,
                'activo' => $i % 5 !== 0,
            ]);
        }
        TramiteRegistro::create([
            'userId' => $op2->id, 'serviceId' => $this->ingId, 'date' => $day,
            'placa' => '999ZZZ', 'observaciones' => 'ajeno', 'activo' => true,
        ]);
        TramiteRegistro::create([
            'userId' => $op2->id, 'serviceId' => $this->ingId, 'date' => $day,
            'placa' => '998ZZZ', 'observaciones' => 'ajeno', 'activo' => true,
        ]);

        // Primera página: 10 de 25, con metadatos y activos del alcance.
        $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&page=1&limit=10")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('page', 1)
            ->assertJsonPath('limit', 10)
            ->assertJsonPath('totalPages', 3)
            ->assertJsonPath('activos', 20);

        // Última página: los 5 restantes.
        $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&page=3&limit=10")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('page', 3);

        // Fuera de rango: página válida pero vacía, metadatos intactos.
        $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&page=9&limit=10")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 25)
            ->assertJsonPath('totalPages', 3);

        // El ADMIN pagina sobre todos los usuarios.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&page=1&limit=20")
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('total', 27)
            ->assertJsonPath('totalPages', 2)
            ->assertJsonPath('activos', 22);

        // Búsqueda + paginación: el total refleja el filtro.
        $this->actingAs($op1, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&search=buscar&page=1&limit=10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('totalPages', 1)
            ->assertJsonPath('activos', 1)
            ->assertJsonPath('data.0.observaciones', 'buscar especial');

        // La paginación no altera las exportaciones (siguen sin paginar).
        $this->assertCount(25, app(TramiteRegistroService::class)->forRange($op1, $today, $today));

        // Validación de page y limit.
        foreach (['page=0', 'page=abc', 'limit=4', 'limit=101', 'limit=abc'] as $query) {
            $this->actingAs($op1, 'sanctum')
                ->getJson("/api/tramites?from={$today}&to={$today}&{$query}")
                ->assertStatus(400);
        }
    }

    public function test_resumen_por_tipo_y_eliminacion_logica(): void
    {
        $opIng = $this->makeUser('ing1', 'OPERATOR_INGRESO', ['firstName' => 'Ana', 'lastName' => 'Quispe']);
        $opEnt = $this->makeUser('ent1', 'OPERATOR_ENTREGA', ['firstName' => 'Luis', 'lastName' => 'Mamani']);
        $admin = $this->makeUser('admin1', 'ADMIN');
        $day = now('UTC')->startOfDay();
        $today = $day->toDateString();

        $rows = [
            [$opIng, $this->ingId, '111AAA', true, 'inscripcion uno'],
            [$opIng, $this->ingId, '112AAA', true, 'inscripcion dos'],
            [$opIng, $this->ingId, '113AAA', false, 'inscripcion inactiva'],
            [$opEnt, $this->entId, '221BBB', true, 'placa uno'],
            [$opEnt, $this->entId, '222BBB', true, 'placa dos'],
        ];

        $ids = [];
        foreach ($rows as [$owner, $serviceId, $placa, $activo, $observaciones]) {
            $ids[$placa] = TramiteRegistro::create([
                'userId' => $owner->id,
                'serviceId' => $serviceId,
                'date' => $day,
                'placa' => $placa,
                'observaciones' => $observaciones,
                'activo' => $activo,
            ])->id;
        }

        // ADMIN: el resumen solo contabiliza activos y agrupa por tipo.
        $adminList = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&limit=10")
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('activos', 4);

        $porTipo = collect($adminList->json('porTipo'))->keyBy('serviceId');
        $this->assertCount(2, $porTipo);
        $this->assertSame(2, $porTipo[$this->ingId]['total']);
        $this->assertSame('INGRESO', $porTipo[$this->ingId]['type']);
        $this->assertSame(2, $porTipo[$this->entId]['total']);
        $this->assertSame('ENTREGA', $porTipo[$this->entId]['type']);

        // Operador: solo sus registros (2 activos de 3).
        $ingList = $this->actingAs($opIng, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&limit=10")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('activos', 2)
            ->assertJsonCount(1, 'porTipo');
        $this->assertSame($this->ingId, $ingList->json('porTipo.0.serviceId'));
        $this->assertSame(2, $ingList->json('porTipo.0.total'));

        // La búsqueda también filtra el resumen.
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&search=placa")
            ->assertOk()
            ->assertJsonPath('activos', 2)
            ->assertJsonCount(1, 'porTipo')
            ->assertJsonPath('porTipo.0.serviceId', $this->entId);

        // Otro operador no puede eliminar.
        $this->actingAs($opEnt, 'sanctum')
            ->deleteJson("/api/tramites/{$ids['111AAA']}")
            ->assertStatus(403);
        $this->assertDatabaseHas('tramite_registros', ['id' => $ids['111AAA'], 'activo' => true]);

        // El dueño elimina: el registro queda inactivo y deja de contabilizarse.
        $this->actingAs($opIng, 'sanctum')
            ->deleteJson("/api/tramites/{$ids['112AAA']}")
            ->assertOk()
            ->assertJsonPath('data.activo', false);
        $this->assertDatabaseHas('tramite_registros', ['id' => $ids['112AAA'], 'activo' => false]);

        $after = $this->actingAs($opIng, 'sanctum')
            ->getJson("/api/tramites?from={$today}&to={$today}&limit=10")
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('activos', 1);
        $this->assertSame(1, $after->json('porTipo.0.total'));

        // El ADMIN también puede eliminar registros de otros usuarios.
        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/tramites/{$ids['221BBB']}")
            ->assertOk()
            ->assertJsonPath('data.activo', false);
    }

    public function test_exportaciones_pdf_y_excel(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO', ['firstName' => 'Ana', 'lastName' => 'Quispe']);
        $today = now('UTC')->toDateString();

        TramiteRegistro::create([
            'userId' => $op->id,
            'serviceId' => $this->ingId,
            'date' => now('UTC')->startOfDay(),
            'placa' => '111AAA',
            'ticket' => 'TK1',
            'estado' => 'recepcionado',
            'observaciones' => 'exportable',
            'activo' => true,
        ]);

        $pdf = $this->actingAs($op, 'sanctum')
            ->get("/api/tramites/export/pdf?from={$today}&to={$today}");
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));

        $excel = $this->actingAs($op, 'sanctum')
            ->get("/api/tramites/export/excel?from={$today}&to={$today}");
        $excel->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $excel->headers->get('content-type'));

        // La búsqueda también se aplica a las exportaciones.
        $this->actingAs($op, 'sanctum')
            ->get("/api/tramites/export/pdf?from={$today}&to={$today}&search=111AAA")
            ->assertOk();

        $this->actingAs($op, 'sanctum')
            ->get("/api/tramites/export/excel?from={$today}&to={$today}&search=111AAA")
            ->assertOk();
    }

    public function test_contenido_de_exportaciones_respeta_alcance_por_rol(): void
    {
        $op1 = $this->makeUser('ing1', 'OPERATOR_INGRESO', ['firstName' => 'Ana', 'lastName' => 'Quispe']);
        $op2 = $this->makeUser('ing2', 'OPERATOR_INGRESO', ['firstName' => 'Pedro', 'lastName' => 'Ramirez']);
        $admin = $this->makeUser('admin1', 'ADMIN');
        $day = now('UTC')->startOfDay();
        $today = $day->toDateString();

        TramiteRegistro::create([
            'userId' => $op1->id, 'serviceId' => $this->ingId, 'date' => $day,
            'placa' => '111AAA', 'ticket' => 'TK1', 'estado' => 'recepcionado',
            'observaciones' => 'solo propio', 'activo' => true,
        ]);
        TramiteRegistro::create([
            'userId' => $op2->id, 'serviceId' => $this->ingId, 'date' => $day,
            'placa' => '222BBB', 'observaciones' => 'ajeno', 'activo' => true,
        ]);

        $service = app(TramiteReportService::class);

        // El operador solo exporta su registro.
        $own = $service->excel($op1, $today, $today);
        $own->deleteFileAfterSend(false);
        $ownPath = $own->getFile()->getPathname();
        $ownSheet = IOFactory::load($ownPath)->getActiveSheet();
        $this->assertSame('REPORTE DE REGISTRO DE TRÁMITES', $ownSheet->getCell('A1')->getValue());
        $this->assertSame('111AAA', $ownSheet->getCell('D9')->getValue());
        $this->assertSame('Ticket', $ownSheet->getCell('E8')->getValue());
        $this->assertSame('TK1', $ownSheet->getCell('E9')->getValue());
        $this->assertSame('Estado', $ownSheet->getCell('H8')->getValue());
        $this->assertSame('Recepcionado', $ownSheet->getCell('H9')->getValue());
        $this->assertSame('Activo', $ownSheet->getCell('I8')->getValue());
        $this->assertSame(9, $ownSheet->getHighestDataRow());

        $pageSetup = $ownSheet->getPageSetup();
        $this->assertSame(PageSetup::PAPERSIZE_LETTER, $pageSetup->getPaperSize());
        $this->assertSame(PageSetup::ORIENTATION_PORTRAIT, $pageSetup->getOrientation());
        $this->assertSame(1, $pageSetup->getFitToWidth());
        $this->assertSame(0, $pageSetup->getFitToHeight());

        @unlink($ownPath);

        // El admin exporta los de todos.
        $all = $service->excel($admin, $today, $today);
        $all->deleteFileAfterSend(false);
        $allPath = $all->getFile()->getPathname();
        $allSheet = IOFactory::load($allPath)->getActiveSheet();
        $this->assertSame(10, $allSheet->getHighestDataRow());
        @unlink($allPath);

        // El PDF se genera correctamente.
        $pdf = $service->pdf($op1, $today, $today);
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_editar_solo_dueno_o_admin(): void
    {
        $owner = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $other = $this->makeUser('ing2', 'OPERATOR_INGRESO');
        $admin = $this->makeUser('admin1', 'ADMIN');
        $today = now('UTC')->toDateString();

        $row = $this->actingAs($owner, 'sanctum')->postJson('/api/tramites', [
            'date' => $today, 'serviceId' => $this->ingId, 'placa' => '123ABC', 'ticket' => 'E1',
        ])->assertCreated();
        $id = $row->json('data.id');

        // Otro operador no puede editar.
        $this->actingAs($other, 'sanctum')
            ->patchJson("/api/tramites/{$id}", ['placa' => '999XYZ'])
            ->assertStatus(403);

        // El dueño sí.
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/tramites/{$id}", ['placa' => '456DEF', 'activo' => false])
            ->assertOk()->assertJsonPath('data.placa', '456DEF');

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
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $today = now('UTC')->toDateString();

        foreach ([[$op1, '111AAA', 'D1'], [$op2, '222BBB', 'D2']] as [$op, $placa, $ticket]) {
            $this->actingAs($op, 'sanctum')->postJson('/api/tramites', [
                'date' => $today, 'serviceId' => $this->ingId, 'placa' => $placa, 'ticket' => $ticket,
            ])->assertCreated();
        }

        $this->actingAs($op1, 'sanctum')->getJson("/api/tramites?date={$today}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->getJson("/api/tramites?date={$today}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($jefe, 'sanctum')->getJson("/api/tramites?date={$today}")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_inactivos_no_cuentan_en_dashboard_ni_totales(): void
    {
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');
        $jefe = $this->makeUser('jefe1', 'JEFE');
        $today = now('UTC')->toDateString();

        foreach ([['111AAA', true], ['222BBB', true], ['333CCC', false]] as [$placa, $activo]) {
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
