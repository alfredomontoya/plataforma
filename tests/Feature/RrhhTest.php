<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\EmpleadoPuesto;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo RRHH: áreas, puestos, empleados (con rotación de puestos),
 * tratamientos y vínculo 1:1 empleado ↔ usuario de sistema.
 */
class RrhhTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser('admin1', 'ADMIN');
        $this->actingAs($this->admin, 'sanctum');
    }

    private function makeUser(string $username, string $role): User
    {
        return $this->userFor($username, $role, [], ['nombre' => 'Test', 'apellidos' => 'User']);
    }

    private function crearArea(string $nombre = 'Atención al público'): array
    {
        return $this->postJson('/api/rrhh/areas', ['nombre' => $nombre])
            ->assertCreated()->json('data');
    }

    private function crearPuesto(string $areaId, string $nombre = 'Ventanilla', int $nivel = 2): array
    {
        return $this->postJson('/api/rrhh/puestos', [
            'areaId' => $areaId, 'nombre' => $nombre, 'nivel' => $nivel,
        ])->assertCreated()->json('data');
    }

    private function crearEmpleado(array $extra = []): array
    {
        return $this->postJson('/api/rrhh/empleados', array_merge([
            'ci' => '12345678', 'nombre' => 'Ana', 'apellidos' => 'Pérez',
        ], $extra))->assertCreated()->json('data');
    }

    public function test_areas_crud_y_borrado_bloqueado_con_puestos(): void
    {
        $area = $this->crearArea();
        $this->assertSame('Atención al público', $area['nombre']);

        // Nombre duplicado
        $this->postJson('/api/rrhh/areas', ['nombre' => 'Atención al público'])->assertStatus(400);

        $this->patchJson("/api/rrhh/areas/{$area['id']}", ['nombre' => 'Atención ciudadana'])
            ->assertOk()->assertJsonPath('data.nombre', 'Atención ciudadana');

        $this->crearPuesto($area['id']);
        $this->deleteJson("/api/rrhh/areas/{$area['id']}")->assertStatus(400);

        $otra = $this->crearArea('Recursos Humanos');
        $this->deleteJson("/api/rrhh/areas/{$otra['id']}")->assertOk();
    }

    public function test_area_padre_no_permite_ciclos_y_protege_subareas(): void
    {
        $padre = $this->postJson('/api/rrhh/areas', ['nombre' => 'Padre', 'codigo' => 'PADRE'])
            ->assertCreated()->json('data');
        $hijo = $this->postJson('/api/rrhh/areas', [
            'nombre' => 'Hijo', 'codigo' => 'HIJO', 'padreId' => $padre['id'],
        ])->assertCreated()->json('data');

        $this->assertSame($padre['id'], $hijo['padreId']);

        // Código duplicado
        $this->postJson('/api/rrhh/areas', ['nombre' => 'Otro', 'codigo' => 'PADRE'])
            ->assertStatus(400);

        // No puede ser su propio padre
        $this->patchJson("/api/rrhh/areas/{$padre['id']}", ['padreId' => $padre['id']])
            ->assertStatus(400);

        // Ciclo: el padre no puede colgar de su hijo
        $this->patchJson("/api/rrhh/areas/{$padre['id']}", ['padreId' => $hijo['id']])
            ->assertStatus(400);

        // No se puede borrar un área con subáreas
        $this->deleteJson("/api/rrhh/areas/{$padre['id']}")->assertStatus(400);

        $this->deleteJson("/api/rrhh/areas/{$hijo['id']}")->assertOk();
        $this->deleteJson("/api/rrhh/areas/{$padre['id']}")->assertOk();
    }

    public function test_permisos_jefe_lee_pero_no_escribe_y_operador_no_accede(): void
    {
        $area = $this->crearArea();

        $jefe = $this->makeUser('jefe1', 'JEFE');
        $this->actingAs($jefe, 'sanctum');
        $this->getJson('/api/rrhh/areas')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/rrhh/areas', ['nombre' => 'Otra'])->assertStatus(403);

        $op = $this->makeUser('op1', 'OPERATOR_INGRESO');
        $this->actingAs($op, 'sanctum');
        $this->getJson('/api/rrhh/areas')->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson("/api/rrhh/areas/{$area['id']}")->assertOk();
    }

    public function test_puestos_unicos_por_area_y_nivel_validado(): void
    {
        $area = $this->crearArea();
        $otra = $this->crearArea('Recursos Humanos');

        $puesto = $this->crearPuesto($area['id'], 'Ventanilla', 2);
        $this->assertSame($area['id'], $puesto['areaId']);

        // Mismo nombre en la misma área
        $this->postJson('/api/rrhh/puestos', [
            'areaId' => $area['id'], 'nombre' => 'Ventanilla', 'nivel' => 3,
        ])->assertStatus(400);

        // Mismo nombre en otra área sí se permite
        $this->crearPuesto($otra['id'], 'Ventanilla', 2);

        // Nivel fuera de escala
        $this->postJson('/api/rrhh/puestos', [
            'areaId' => $area['id'], 'nombre' => 'Jefe', 'nivel' => 9,
        ])->assertStatus(400);

        $this->getJson("/api/rrhh/puestos?areaId={$area['id']}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_asignacion_y_rotacion_de_puesto_cierra_la_vigente(): void
    {
        $area = $this->crearArea();
        $p1 = $this->crearPuesto($area['id'], 'Ventanilla', 2);
        $p2 = $this->crearPuesto($area['id'], 'Supervisor', 5);
        $emp = $this->crearEmpleado();

        $a1 = $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", [
            'puestoId' => $p1['id'], 'fechaInicio' => '2026-01-01',
        ])->assertCreated()->json('data');

        $a2 = $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", [
            'puestoId' => $p2['id'], 'fechaInicio' => '2026-03-01',
        ])->assertCreated()->json('data');

        $this->assertSame('2026-02-28', EmpleadoPuesto::find($a1['id'])->fechaFin->toDateString());
        $this->assertNull(EmpleadoPuesto::find($a2['id'])->fechaFin);
        $this->assertSame(1, EmpleadoPuesto::where('empleadoId', $emp['id'])->whereNull('fechaFin')->count());

        // Cerrar con fecha anterior al inicio → 400
        $this->patchJson("/api/rrhh/empleados/{$emp['id']}/puestos/{$a2['id']}", [
            'fechaFin' => '2026-02-01',
        ])->assertStatus(400);

        $this->patchJson("/api/rrhh/empleados/{$emp['id']}/puestos/{$a2['id']}", [
            'fechaFin' => '2026-04-01',
        ])->assertOk();

        $this->getJson("/api/rrhh/empleados/{$emp['id']}/historial")
            ->assertOk()->assertJsonCount(2, 'data');

        // El puesto actual desaparece al cerrar la última asignación.
        $this->getJson("/api/rrhh/empleados/{$emp['id']}")
            ->assertOk()->assertJsonPath('data.puesto_actual', null);
    }

    public function test_supervisor_debe_ser_otro_empleado_activo(): void
    {
        $area = $this->crearArea();
        $puesto = $this->crearPuesto($area['id']);
        $emp = $this->crearEmpleado(['ci' => '111111', 'nombre' => 'Luis', 'apellidos' => 'Gómez']);

        // No puede ser su propio supervisor
        $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", [
            'puestoId' => $puesto['id'], 'supervisorId' => $emp['id'],
        ])->assertStatus(400);

        $jefe = $this->crearEmpleado(['ci' => '222222', 'nombre' => 'Marta', 'apellidos' => 'Rojas']);
        $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", [
            'puestoId' => $puesto['id'], 'supervisorId' => $jefe['id'],
        ])->assertCreated()->assertJsonPath('data.supervisorId', $jefe['id']);

        // Un empleado inactivo no puede asignarse
        $this->deleteJson("/api/rrhh/empleados/{$jefe['id']}")->assertOk();
        $otro = $this->crearEmpleado(['ci' => '333333', 'nombre' => 'Pedro', 'apellidos' => 'Silva']);
        $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", [
            'puestoId' => $puesto['id'], 'supervisorId' => $jefe['id'],
        ])->assertStatus(400);
        $this->postJson("/api/rrhh/empleados/{$otro['id']}/puestos", [
            'puestoId' => $puesto['id'], 'supervisorId' => $jefe['id'],
        ])->assertStatus(400);
    }

    public function test_baja_logica_cierra_asignacion_y_no_permite_reasignar(): void
    {
        $area = $this->crearArea();
        $puesto = $this->crearPuesto($area['id']);
        $emp = $this->crearEmpleado();

        $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", ['puestoId' => $puesto['id']])
            ->assertCreated();

        $this->deleteJson("/api/rrhh/empleados/{$emp['id']}")->assertOk();

        $this->assertSame(Empleado::ESTADO_INACTIVO, Empleado::find($emp['id'])->estado);
        $this->assertSame(0, EmpleadoPuesto::where('empleadoId', $emp['id'])->whereNull('fechaFin')->count());

        $this->postJson("/api/rrhh/empleados/{$emp['id']}/puestos", ['puestoId' => $puesto['id']])
            ->assertStatus(400);
    }

    public function test_tratamientos_crud_y_no_se_borra_uno_en_uso(): void
    {
        $trat = $this->postJson('/api/rrhh/tratamientos', [
            'nombre' => 'Licenciado', 'abreviatura' => 'Lic.',
        ])->assertCreated()->json('data');

        $this->postJson('/api/rrhh/tratamientos', [
            'nombre' => 'Licenciado', 'abreviatura' => 'L.',
        ])->assertStatus(400);

        $this->crearEmpleado(['tratamientoId' => $trat['id']]);

        $this->deleteJson("/api/rrhh/tratamientos/{$trat['id']}")->assertStatus(400);

        $this->patchJson("/api/rrhh/tratamientos/{$trat['id']}", ['activo' => false])
            ->assertOk()->assertJsonPath('data.activo', false);
    }

    public function test_vinculo_usuario_empleado_usa_la_ficha_y_es_unico(): void
    {
        $emp = $this->crearEmpleado(['nombre' => 'Ana', 'apellidos' => 'Pérez']);

        $user = $this->postJson('/api/users', [
            'username' => 'aperez', 'password' => 'password', 'email' => 'aperez@test.local',
            'role' => 'OPERATOR_INGRESO', 'empleadoId' => $emp['id'],
        ])->assertCreated()->json('data');

        $this->assertSame($emp['id'], $user['empleadoId']);
        $this->assertArrayNotHasKey('firstName', $user);
        $this->assertArrayNotHasKey('lastName', $user);
        $this->assertSame('Ana Pérez', User::find($user['id'])->fullName);

        // Un empleado solo puede tener una cuenta
        $this->postJson('/api/users', [
            'username' => 'aperez2', 'password' => 'password', 'email' => 'aperez2@test.local',
            'role' => 'OPERATOR_INGRESO', 'empleadoId' => $emp['id'],
        ])->assertStatus(400);
    }

    public function test_filtros_de_empleados_por_area_puesto_y_estado(): void
    {
        $areaA = $this->crearArea('Área A');
        $areaB = $this->crearArea('Área B');
        $puestoA = $this->crearPuesto($areaA['id'], 'Puesto A');
        $puestoB = $this->crearPuesto($areaB['id'], 'Puesto B');

        $empA = $this->crearEmpleado(['ci' => '1001', 'nombre' => 'Ana', 'apellidos' => 'Pérez']);
        $empB = $this->crearEmpleado(['ci' => '1002', 'nombre' => 'Beto', 'apellidos' => 'Quispe']);

        $this->postJson("/api/rrhh/empleados/{$empA['id']}/puestos", ['puestoId' => $puestoA['id']]);
        $this->postJson("/api/rrhh/empleados/{$empB['id']}/puestos", ['puestoId' => $puestoB['id']]);

        $this->getJson("/api/rrhh/empleados?areaId={$areaA['id']}")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empA['id']);

        $this->getJson('/api/rrhh/empleados?search=Beto')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empB['id']);

        $this->deleteJson("/api/rrhh/empleados/{$empA['id']}")->assertOk();
        $this->getJson('/api/rrhh/empleados?estado=inactivo')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $empA['id']);
    }

    public function test_busqueda_por_nombre_completo_y_alta_con_puesto_en_transaccion(): void
    {
        $area = $this->crearArea();
        $puesto = $this->crearPuesto($area['id'], 'Soporte', 3);

        // El alta puede incluir la asignación inicial en la misma operación.
        $emp = $this->postJson('/api/rrhh/empleados', [
            'ci' => '55555555',
            'nombre' => 'Alfredo',
            'apellidos' => 'Montoya Calderon',
            'puestoId' => $puesto['id'],
            'fechaInicio' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.puesto_actual.puesto.id', $puesto['id'])
            ->json('data');

        $asignacion = EmpleadoPuesto::where('empleadoId', $emp['id'])->firstOrFail();
        $this->assertSame($puesto['id'], $asignacion->puestoId);
        $this->assertSame('2026-01-01', $asignacion->fechaInicio->toDateString());
        $this->assertNull($asignacion->fechaFin);

        // Combinaciones de nombres y apellidos en cualquier orden.
        $this->getJson('/api/rrhh/empleados?search=Alfredo Montoya')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $emp['id']);
        $this->getJson('/api/rrhh/empleados?search=Montoya Alfredo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $emp['id']);
        $this->getJson('/api/rrhh/empleados?search=55555555')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $emp['id']);

        // Si falla la asignación, el empleado no queda creado (rollback).
        $inactivo = $this->crearEmpleado(['ci' => '55555556', 'nombre' => 'Marta', 'apellidos' => 'Rojas']);
        $this->deleteJson("/api/rrhh/empleados/{$inactivo['id']}")->assertOk();

        $this->postJson('/api/rrhh/empleados', [
            'ci' => '55555557',
            'nombre' => 'Pedro',
            'apellidos' => 'Silva',
            'puestoId' => $puesto['id'],
            'fechaInicio' => '2026-01-01',
            'supervisorId' => $inactivo['id'],
        ])->assertStatus(400);

        $this->assertDatabaseMissing('empleados', ['ci' => '55555557']);
    }
}
