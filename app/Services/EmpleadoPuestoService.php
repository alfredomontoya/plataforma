<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\EmpleadoPuesto;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rotación de puestos de un empleado.
 *
 * El puesto vigente es la asignación con `fechaFin` NULL. Al asignar uno
 * nuevo, la asignación vigente se cierra el día anterior al inicio para no
 * solapar periodos. La unicidad del vigente se garantiza aquí (no con un
 * índice único: MySQL permite múltiples NULL).
 */
class EmpleadoPuestoService
{
    public function assign(Empleado $empleado, array $data): EmpleadoPuesto
    {
        return DB::transaction(function () use ($empleado, $data) {
            $inicio = isset($data['fechaInicio']) && $data['fechaInicio']
                ? Carbon::parse($data['fechaInicio'], 'UTC')->startOfDay()
                : Carbon::now('UTC')->startOfDay();

            $supervisorId = $data['supervisorId'] ?? null;
            if ($supervisorId) {
                abort_if($supervisorId === $empleado->id, 400, 'Un empleado no puede ser su propio supervisor.');
                $supervisor = Empleado::find($supervisorId);
                abort_if(! $supervisor || $supervisor->estado !== Empleado::ESTADO_ACTIVO, 400, 'El supervisor debe ser un empleado activo.');
            }

            // Cierra la asignación vigente el día anterior al nuevo inicio.
            $empleado->asignaciones()->whereNull('fechaFin')->get()
                ->each(fn (EmpleadoPuesto $actual) => $actual->update([
                    'fechaFin' => $inicio->copy()->subDay()->toDateString(),
                ]));

            return EmpleadoPuesto::create([
                'empleadoId' => $empleado->id,
                'puestoId' => $data['puestoId'],
                'fechaInicio' => $inicio->toDateString(),
                'fechaFin' => null,
                'supervisorId' => $supervisorId,
            ]);
        });
    }

    public function close(EmpleadoPuesto $asignacion, ?string $fechaFin = null): EmpleadoPuesto
    {
        abort_if($asignacion->fechaFin !== null, 400, 'La asignación ya está cerrada.');

        $fin = $fechaFin
            ? Carbon::parse($fechaFin, 'UTC')->startOfDay()
            : Carbon::now('UTC')->startOfDay();

        abort_if($fin->lt(Carbon::parse($asignacion->fechaInicio)), 400, 'La fecha de fin no puede ser anterior al inicio.');

        $asignacion->update(['fechaFin' => $fin->toDateString()]);

        return $asignacion->fresh(['puesto.area', 'supervisor']);
    }
}
