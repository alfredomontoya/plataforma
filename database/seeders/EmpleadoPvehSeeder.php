<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Empleado;
use App\Models\Puesto;
use App\Services\EmpleadoPuestoService;
use Illuminate\Database\Seeder;

/**
 * Empleados demo de PLATAFORMA DE VEHICULO (PVEH).
 *
 * Crea la plantilla solicitada por puesto y la asigna usando
 * EmpleadoPuestoService, respetando la jerarquía de supervisión.
 *
 * Idempotente: los CI son deterministas y las asignaciones vigentes se
 * reutilizan si coinciden puesto + supervisor. No corre en producción.
 */
class EmpleadoPvehSeeder extends Seeder
{
    /** Primer CI reservado para la plantilla demo de PVEH. */
    private const CI_BASE = 90000000;

    /** Cantidad de empleados por sigla de puesto. */
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

    /** Supervisor directo por sigla (null = sin supervisor). */
    private const SUPERVISOR = [
        'JPV' => null,
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

    /** Datos fijos para empleados que no se generan con el factory. */
    private const PERFILES_FIJOS = [
        'SOP' => [
            'ci' => '90000022',
            'nombre' => 'Alfredo',
            'apellidos' => 'Montoya Calderon',
        ],
    ];

    public function run(): void
    {
        if (! DatabaseSeeder::destructiveAllowed()) {
            $this->command?->warn('EmpleadoPvehSeeder omitido: no se siembran empleados demo en producción.');

            return;
        }

        $area = Area::where('codigo', 'PVEH')->first();
        if (! $area) {
            $this->command?->warn('EmpleadoPvehSeeder omitido: no existe el área PVEH.');

            return;
        }

        $puestos = Puesto::where('areaId', $area->id)
            ->whereIn('sigla', array_keys(self::DISTRIBUCION))
            ->get()
            ->keyBy('sigla');

        $faltantes = array_diff(array_keys(self::DISTRIBUCION), $puestos->keys()->all());
        if ($faltantes !== []) {
            $this->command?->error(
                'EmpleadoPvehSeeder omitido: faltan puestos en PVEH ('.implode(', ', $faltantes).').'
            );

            return;
        }

        $creados = [];
        $ci = self::CI_BASE;

        foreach (self::DISTRIBUCION as $sigla => $cantidad) {
            for ($i = 0; $i < $cantidad; $i++) {
                $ci++;
                $perfil = $i === 0 ? (self::PERFILES_FIJOS[$sigla] ?? null) : null;
                $datos = $perfil
                    ? [
                        'ci' => $perfil['ci'],
                        'nombre' => $perfil['nombre'],
                        'apellidos' => $perfil['apellidos'],
                    ]
                    : ['ci' => (string) $ci];

                $empleado = Empleado::where('ci', $datos['ci'])->first()
                    ?? Empleado::factory()->create($datos);

                if ($perfil && ($empleado->nombre !== $perfil['nombre'] || $empleado->apellidos !== $perfil['apellidos'])) {
                    $empleado->update([
                        'nombre' => $perfil['nombre'],
                        'apellidos' => $perfil['apellidos'],
                    ]);
                }

                $creados[$sigla][] = $empleado;
            }
        }

        $servicio = app(EmpleadoPuestoService::class);
        $fechaInicio = now()->subMonths(6)->toDateString();

        foreach (self::DISTRIBUCION as $sigla => $cantidad) {
            $supervisorSigla = self::SUPERVISOR[$sigla];
            $supervisor = $supervisorSigla
                ? ($creados[$supervisorSigla][0] ?? null)
                : null;

            if ($supervisorSigla && ! $supervisor) {
                $this->command?->error(
                    "EmpleadoPvehSeeder: no se creó el supervisor {$supervisorSigla} para {$sigla}."
                );

                return;
            }

            foreach ($creados[$sigla] as $empleado) {
                $actual = $empleado->asignaciones()->whereNull('fechaFin')->first();

                if ($actual
                    && $actual->puestoId === $puestos[$sigla]->id
                    && $actual->supervisorId === $supervisor?->id) {
                    continue;
                }

                $servicio->assign($empleado, [
                    'puestoId' => $puestos[$sigla]->id,
                    'fechaInicio' => $fechaInicio,
                    'supervisorId' => $supervisor?->id,
                ]);
            }
        }

        $this->command?->info('EmpleadoPvehSeeder: '.array_sum(self::DISTRIBUCION).' empleados demo de PVEH listos.');
    }
}
