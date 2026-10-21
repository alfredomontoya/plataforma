<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Puesto;
use Illuminate\Database\Seeder;

/**
 * Puestos iniciales de PLATAFORMA DE VEHICULO (PVEH).
 *
 * Idempotente: usa (areaId, nombre) como clave natural. No corre en producción
 * (los catálogos reales se cargan manualmente desde el módulo RRHH).
 */
class PuestoPvehSeeder extends Seeder
{
    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('PuestoPvehSeeder omitido: no se siembran puestos demo en producción.');

            return;
        }

        $area = Area::where('codigo', 'PVEH')->first();
        if (! $area) {
            $this->command?->warn('PuestoPvehSeeder omitido: no existe el área PVEH.');

            return;
        }

        $puestos = [
            ['nombre' => 'Jefe de Plataforma de Vehículo', 'sigla' => 'JPV', 'nivel' => 7, 'esSupervisor' => true],
            ['nombre' => 'Operador de Entrega', 'sigla' => 'OE', 'nivel' => 1, 'esSupervisor' => false],
            ['nombre' => 'Operador de Recepción', 'sigla' => 'OR', 'nivel' => 1, 'esSupervisor' => false],
            ['nombre' => 'Operador de Cierre de Trámites', 'sigla' => 'OCT', 'nivel' => 1, 'esSupervisor' => false],
            ['nombre' => 'Operador de Casos Especiales', 'sigla' => 'OCE', 'nivel' => 1, 'esSupervisor' => false],
            ['nombre' => 'Encargado Ingreso', 'sigla' => 'EI', 'nivel' => 6, 'esSupervisor' => true],
            ['nombre' => 'Encargado Entrega', 'sigla' => 'EE', 'nivel' => 6, 'esSupervisor' => true],
            ['nombre' => 'Encargado Archivo Trámites Pendientes y Observados', 'sigla' => 'EATPO', 'nivel' => 6, 'esSupervisor' => true],
            ['nombre' => 'Encargado Archivo de Placas', 'sigla' => 'EAP', 'nivel' => 6, 'esSupervisor' => true],
            ['nombre' => 'Operador de Verificación', 'sigla' => 'OV', 'nivel' => 1, 'esSupervisor' => false],
            ['nombre' => 'Asistente Archivo', 'sigla' => 'AA', 'nivel' => 2, 'esSupervisor' => false],
            ['nombre' => 'Soporte', 'sigla' => 'SOP', 'nivel' => 3, 'esSupervisor' => false],
        ];

        foreach ($puestos as $puesto) {
            Puesto::updateOrCreate(
                ['areaId' => $area->id, 'nombre' => $puesto['nombre']],
                [
                    'sigla' => $puesto['sigla'],
                    'nivel' => $puesto['nivel'],
                    'esSupervisor' => $puesto['esSupervisor'],
                ]
            );
        }
    }
}
