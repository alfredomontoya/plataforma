<?php

namespace Database\Seeders;

use App\Models\Tratamiento;
use Illuminate\Database\Seeder;

/**
 * Tratamientos (títulos honoríficos) para una institución profesional.
 *
 * Idempotente: usa `nombre` como clave natural. No corre en producción
 * (los catálogos reales se cargan manualmente desde el módulo RRHH).
 */
class TratamientoSeeder extends Seeder
{
    public const DEFAULT = [
        ['Señor', 'Sr.'],
        ['Señora', 'Sra.'],
        ['Doctor', 'Dr.'],
        ['Doctora', 'Dra.'],
        ['Licenciado', 'Lic.'],
        ['Licenciada', 'Licda.'],
        ['Ingeniero', 'Ing.'],
        ['Ingeniera', 'Ing.'],
        ['Abogado', 'Abg.'],
        ['Abogada', 'Abg.'],
        ['Arquitecto', 'Arq.'],
        ['Arquitecta', 'Arq.'],
        ['Contador', 'Cont.'],
        ['Contadora', 'Cont.'],
        ['Economista', 'Econ.'],
        ['Administrador', 'Adm.'],
        ['Administradora', 'Adm.'],
        ['Auditor', 'Aud.'],
        ['Auditora', 'Aud.'],
        ['Magíster', 'Mg.'],
        ['Técnico', 'Téc.'],
        ['Técnica', 'Téc.'],
    ];

    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('TratamientoSeeder omitido: no se siembran tratamientos demo en producción.');

            return;
        }

        foreach (self::DEFAULT as [$nombre, $abreviatura]) {
            Tratamiento::updateOrCreate(
                ['nombre' => $nombre],
                ['abreviatura' => $abreviatura, 'activo' => true]
            );
        }
    }
}
