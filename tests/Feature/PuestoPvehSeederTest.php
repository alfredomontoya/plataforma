<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Puesto;
use Database\Seeders\AreaSeeder;
use Database\Seeders\PuestoPvehSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PuestoPvehSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_siembra_los_puestos_de_pveh_con_siglas_y_niveles(): void
    {
        $this->seed(AreaSeeder::class);
        $this->seed(PuestoPvehSeeder::class);

        $pveh = Area::where('codigo', 'PVEH')->firstOrFail();

        $this->assertSame(12, Puesto::where('areaId', $pveh->id)->count());

        $esperados = [
            'JPV' => ['Jefe de Plataforma de Vehículo', 7, true],
            'OE' => ['Operador de Entrega', 1, false],
            'OR' => ['Operador de Recepción', 1, false],
            'OCT' => ['Operador de Cierre de Trámites', 1, false],
            'OCE' => ['Operador de Casos Especiales', 1, false],
            'EI' => ['Encargado Ingreso', 6, true],
            'EE' => ['Encargado Entrega', 6, true],
            'EATPO' => ['Encargado Archivo Trámites Pendientes y Observados', 6, true],
            'EAP' => ['Encargado Archivo de Placas', 6, true],
            'OV' => ['Operador de Verificación', 1, false],
            'AA' => ['Asistente Archivo', 2, false],
            'SOP' => ['Soporte', 3, false],
        ];

        foreach ($esperados as $sigla => [$nombre, $nivel, $esSupervisor]) {
            $puesto = Puesto::where('areaId', $pveh->id)->where('sigla', $sigla)->first();

            $this->assertNotNull($puesto, "Falta el puesto con sigla {$sigla}.");
            $this->assertSame($nombre, $puesto->nombre);
            $this->assertSame($nivel, $puesto->nivel);
            $this->assertSame($esSupervisor, $puesto->esSupervisor);
        }

        // Segunda ejecución: no duplica.
        $this->seed(PuestoPvehSeeder::class);
        $this->assertSame(12, Puesto::where('areaId', $pveh->id)->count());
    }

    public function test_no_siembra_si_falta_el_area_pveh(): void
    {
        $this->seed(PuestoPvehSeeder::class);

        $this->assertSame(0, Puesto::count());
    }
}
