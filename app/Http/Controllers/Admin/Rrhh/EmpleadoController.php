<?php

namespace App\Http\Controllers\Admin\Rrhh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rrhh\AssignPuestoRequest;
use App\Http\Requests\Rrhh\SaveEmpleadoRequest;
use App\Models\Empleado;
use App\Models\EmpleadoPuesto;
use App\Services\EmpleadoPuestoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ficha de empleado + rotación de puestos.
 *
 * El empleado es la fuente de verdad de los datos personales; el usuario de
 * sistema es 1:1 y toma nombre/apellidos del empleado al vincularse.
 */
class EmpleadoController extends Controller
{
    private const RELATIONS = [
        'tratamiento',
        'puestoActual.puesto.area',
        'puestoActual.supervisor',
        'usuario',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = Empleado::query()
            ->with('tratamiento')
            ->with('puestoActual.puesto.area')
            ->with(['usuario' => fn ($query) => $query->select('id', 'username', 'empleadoId')]);

        if ($search = trim((string) $request->query('search'))) {
            // Cada término debe aparecer en CI, nombre o apellidos. Esto
            // permite buscar combinaciones como "Alfredo Montoya".
            $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            $q->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                    $query->where(fn ($w) => $w->where('ci', 'like', $like)
                        ->orWhere('nombre', 'like', $like)
                        ->orWhere('apellidos', 'like', $like));
                }
            });
        }
        if ($areaId = $request->query('areaId')) {
            $q->whereHas('puestoActual.puesto', fn ($p) => $p->where('areaId', $areaId));
        }
        if ($puestoId = $request->query('puestoId')) {
            $q->whereHas('puestoActual', fn ($p) => $p->where('puestoId', $puestoId));
        }
        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }

        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        $paginator = $q->orderBy('apellidos')->orderBy('nombre')
            ->paginate($limit, ['*'], 'page', $page);

        return ApiResponse::paginated($paginator);
    }

    public function show(string $id): JsonResponse
    {
        $empleado = Empleado::with(array_merge(self::RELATIONS, [
            'asignaciones.puesto.area',
            'asignaciones.supervisor',
        ]))->findOrFail($id);

        return ApiResponse::ok($empleado);
    }

    public function store(SaveEmpleadoRequest $request, EmpleadoPuestoService $puestos): JsonResponse
    {
        $data = $request->validated();
        $asignacion = [
            'puestoId' => $data['puestoId'] ?? null,
            'fechaInicio' => $data['fechaInicio'] ?? null,
            'supervisorId' => $data['supervisorId'] ?? null,
        ];

        unset($data['puestoId'], $data['fechaInicio'], $data['supervisorId']);
        $data['creadoPorEmpleadoId'] = $request->user()?->empleadoId;

        $empleado = DB::transaction(function () use ($data, $asignacion, $puestos) {
            $empleado = Empleado::create($data);

            if (! empty($asignacion['puestoId'])) {
                abort_if(
                    ($data['estado'] ?? Empleado::ESTADO_ACTIVO) !== Empleado::ESTADO_ACTIVO,
                    400,
                    'No se puede asignar un puesto a un empleado inactivo.'
                );

                $puestos->assign($empleado, $asignacion);
            }

            return $empleado;
        });

        return ApiResponse::created($empleado->load(self::RELATIONS));
    }

    public function update(SaveEmpleadoRequest $request, string $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        $empleado->update($request->validated());

        return ApiResponse::ok($empleado->fresh(self::RELATIONS));
    }

    /**
     * Baja lógica: el empleado no se borra (conserva historial y auditoría).
     * Además se cierra la asignación vigente para que deje de contar como
     * puesto activo.
     */
    public function destroy(string $id, EmpleadoPuestoService $puestos): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);
        $empleado->update(['estado' => Empleado::ESTADO_INACTIVO]);

        $vigente = $empleado->asignaciones()->whereNull('fechaFin')->first();
        if ($vigente) {
            $puestos->close($vigente);
        }

        return ApiResponse::ok(['message' => 'Empleado dado de baja.']);
    }

    public function assignPuesto(AssignPuestoRequest $request, string $id, EmpleadoPuestoService $puestos): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);

        abort_if($empleado->estado !== Empleado::ESTADO_ACTIVO, 400, 'No se puede asignar un puesto a un empleado inactivo.');

        $asignacion = $puestos->assign($empleado, $request->validated());

        return ApiResponse::created($asignacion->load(['puesto.area', 'supervisor']));
    }

    public function closePuesto(Request $request, string $id, string $asignacionId, EmpleadoPuestoService $puestos): JsonResponse
    {
        $data = $request->validate([
            'fechaFin' => ['nullable', 'date'],
        ]);

        $asignacion = EmpleadoPuesto::where('empleadoId', $id)->findOrFail($asignacionId);

        return ApiResponse::ok($puestos->close($asignacion, $data['fechaFin'] ?? null));
    }

    public function historial(string $id): JsonResponse
    {
        $empleado = Empleado::findOrFail($id);

        return ApiResponse::ok($empleado->asignaciones()
            ->with(['puesto.area', 'supervisor'])
            ->orderByDesc('fechaInicio')
            ->get());
    }
}
