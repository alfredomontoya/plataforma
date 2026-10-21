<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\EmpleadoPuesto;
use App\Models\Puesto;
use App\Models\Tratamiento;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_recrea_todos_los_datos_demo_en_cada_ejecucion(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDemoData();

        $this->seed(DatabaseSeeder::class);
        $this->assertDemoData();
    }

    private function assertDemoData(): void
    {
        $this->assertSame(22, Empleado::count());
        $this->assertSame(22, EmpleadoPuesto::whereNull('fechaFin')->count());
        $this->assertSame(12, Puesto::count());
        $this->assertSame(22, Tratamiento::count());
        $this->assertSame(22, User::count());
        $this->assertTrue(Puesto::where('sigla', 'JPV')->where('nivel', 7)->exists());
        $this->assertTrue(Puesto::where('sigla', 'SOP')->where('nivel', 3)->exists());
        $this->assertTrue(User::where('username', 'amontoya')->where('role', 'ADMIN')->exists());
    }
}
