<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Empleado;
use App\Models\EmpleadoPuesto;
use App\Models\Puesto;
use Database\Seeders\AreaSeeder;
use Database\Seeders\EmpleadoPvehSeeder;
use Database\Seeders\PuestoPvehSeeder;
use Database\Seeders\TratamientoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpleadoPvehSeederTest extends TestCase
{
    use RefreshDatabase;

    private const DISTRIBUCION = [
        'JPV' => 1,
        'EE' => 1,
        'EI' => 1,
        'OR' => 5,
        'OE' => 5,
        'OCT' => 2,
        'OCE' => 2,
        'OV' => 1,
        'AA' => 1,
        'EAP' => 1,
        'EATPO' => 1,
        'SOP' => 1,
    ];

    private const SUPERVISORES = [
        'EE' => 'JPV',
        'EI' => 'JPV',
        'OR' => 'EI',
        'OE' => 'EE',
        'OCT' => 'EATPO',
        'OCE' => 'EATPO',
        'OV' => 'EI',
        'AA' => 'EAP',
        'EAP' => 'JPV',
        'EATPO' => 'JPV',
        'SOP' => 'JPV',
    ];

    public function test_siembra_la_plantilla_solicitada_para_pveh(): void
    {
        $this->seedBase();
        $this->seed(EmpleadoPvehSeeder::class);

        $this->assertSame(array_sum(self::DISTRIBUCION), Empleado::count());
        $this->assertSame(array_sum(self::DISTRIBUCION), EmpleadoPuesto::whereNull('fechaFin')->count());
        $this->assertDatabaseHas('empleados', ['ci' => '90000001']);
        $this->assertDatabaseHas('empleados', ['ci' => '90000021']);
        $this->assertDatabaseHas('empleados', [
            'ci' => '90000022',
            'nombre' => 'Alfredo',
            'apellidos' => 'Montoya Calderon',
        ]);

        foreach (self::DISTRIBUCION as $sigla => $cantidad) {
            $this->assertSame(
                $cantidad,
                $this->asignacionesActivas($sigla)->count(),
                "Cantidad incorrecta de empleados activos en el puesto {$sigla}."
            );
        }
    }

    public function test_asigna_la_jerarquia_de_supervisores(): void
    {
        $this->seedBase();
        $this->seed(EmpleadoPvehSeeder::class);

        foreach (self::SUPERVISORES as $sigla => $supervisorSigla) {
            $asignacion = $this->asignacionesActivas($sigla)->firstOrFail();
            $supervisor = $this->asignacionesActivas($supervisorSigla)->firstOrFail();

            $this->assertSame(
                $supervisor->empleadoId,
                $asignacion->supervisorId,
                "El puesto {$sigla} debe reportar al puesto {$supervisorSigla}."
            );
        }

        $this->assertNull($this->asignacionesActivas('JPV')->firstOrFail()->supervisorId);
    }

    public function test_segunda_ejecucion_no_duplica_empleados_ni_asignaciones(): void
    {
        $this->seedBase();
        $this->seed(EmpleadoPvehSeeder::class);
        $this->seed(EmpleadoPvehSeeder::class);

        $this->assertSame(array_sum(self::DISTRIBUCION), Empleado::count());
        $this->assertSame(array_sum(self::DISTRIBUCION), EmpleadoPuesto::count());
        $this->assertSame(array_sum(self::DISTRIBUCION), EmpleadoPuesto::whereNull('fechaFin')->count());
    }

    public function test_no_siembra_si_falta_el_area_pveh(): void
    {
        $this->seed(EmpleadoPvehSeeder::class);

        $this->assertSame(0, Empleado::count());
        $this->assertSame(0, EmpleadoPuesto::count());
    }

    private function seedBase(): void
    {
        $this->seed([
            AreaSeeder::class,
            PuestoPvehSeeder::class,
            TratamientoSeeder::class,
        ]);
    }

    private function asignacionesActivas(string $sigla)
    {
        $areaId = Area::where('codigo', 'PVEH')->firstOrFail()->id;
        $puestoId = Puesto::where('areaId', $areaId)->where('sigla', $sigla)->firstOrFail()->id;

        return EmpleadoPuesto::where('puestoId', $puestoId)->whereNull('fechaFin');
    }
}
