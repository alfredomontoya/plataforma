<?php

namespace Tests\Feature;

use App\Models\Tratamiento;
use Database\Seeders\TratamientoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TratamientoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_siembra_tratamientos_profesionales_con_abreviatura(): void
    {
        $this->seed(TratamientoSeeder::class);

        $this->assertSame(22, Tratamiento::count());

        foreach (TratamientoSeeder::DEFAULT as [$nombre, $abreviatura]) {
            $tratamiento = Tratamiento::where('nombre', $nombre)->first();

            $this->assertNotNull($tratamiento, "Falta el tratamiento {$nombre}.");
            $this->assertSame($abreviatura, $tratamiento->abreviatura);
            $this->assertTrue($tratamiento->activo);
            $this->assertStringEndsWith('.', $tratamiento->abreviatura);
        }

        // Segunda ejecución: no duplica.
        $this->seed(TratamientoSeeder::class);
        $this->assertSame(22, Tratamiento::count());
    }
}
