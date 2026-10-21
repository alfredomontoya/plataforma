<?php

namespace Tests\Feature;

use App\Models\Area;
use Database\Seeders\AreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_siembra_areas_con_jerarquia_es_idempotente(): void
    {
        $this->seed(AreaSeeder::class);

        $this->assertDatabaseCount('areas', 3);

        $dat = Area::where('codigo', 'DAT')->firstOrFail();
        $plt = Area::where('codigo', 'PLT')->firstOrFail();
        $pveh = Area::where('codigo', 'PVEH')->firstOrFail();

        $this->assertSame('DIRECCION DE ADMINISTRACION TRIBUTARIA', $dat->nombre);
        $this->assertNull($dat->descripcion);
        $this->assertNull($dat->padreId);

        $this->assertSame('DIRECCION DE PLATAFORMA', $plt->nombre);
        $this->assertSame($dat->id, $plt->padreId);

        $this->assertSame('PLATAFORMA DE VEHICULO', $pveh->nombre);
        $this->assertSame($plt->id, $pveh->padreId);

        // Segunda ejecución: no duplica.
        $this->seed(AreaSeeder::class);
        $this->assertSame(3, Area::count());
    }
}
