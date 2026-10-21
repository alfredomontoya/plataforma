<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de áreas con jerarquía organizacional.
 *
 * Idempotente: usa `codigo` como clave natural. No corre en producción
 * (los catálogos reales se cargan manualmente desde el módulo RRHH).
 */
class AreaSeeder extends Seeder
{
    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('AreaSeeder omitido: no se siembran áreas demo en producción.');

            return;
        }

        $dat = Area::updateOrCreate(
            ['codigo' => 'DAT'],
            [
                'nombre' => 'DIRECCION DE ADMINISTRACION TRIBUTARIA',
                'descripcion' => null,
                'padreId' => null,
            ]
        );

        $plt = Area::updateOrCreate(
            ['codigo' => 'PLT'],
            [
                'nombre' => 'DIRECCION DE PLATAFORMA',
                'descripcion' => null,
                'padreId' => $dat->id,
            ]
        );

        Area::updateOrCreate(
            ['codigo' => 'PVEH'],
            [
                'nombre' => 'PLATAFORMA DE VEHICULO',
                'descripcion' => null,
                'padreId' => $plt->id,
            ]
        );
    }
}
