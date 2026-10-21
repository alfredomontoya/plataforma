<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Empleado;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gestión de usuarios — todo solo ADMIN (contrato 04 § Usuarios).
 * El puesto actual se deriva del empleado de RRHH vinculado (empleado_puestos).
 */
class UserController extends Controller
{
    private const SORTS = [
        'username' => 'users.username', 'firstName' => 'users.firstName',
        'role' => 'users.role',
        'isActive' => 'users.isActive', 'createdAt' => 'users.created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->query('sortBy', 'createdAt');
        $sortOrder = strtolower((string) $request->query('sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        $q = User::query()->with(['empleado.puestoActual.puesto', 'empleado.tratamiento']);

        if ($search = $request->query('search')) {
            // (Bajo/Medio) Escapa comodines LIKE (`%` `_` `\`) para búsqueda literal.
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $q->where(fn ($w) => $w->where('users.username', 'like', $like)
                ->orWhere('users.firstName', 'like', $like)
                ->orWhere('users.lastName', 'like', $like));
        }
        if ($request->query('role')) {
            $q->where('users.role', $request->query('role'));
        }
        if ($request->query('isActive') !== null && $request->query('isActive') !== '') {
            $q->where('users.isActive', filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN));
        }

        $q->orderBy(self::SORTS[$sortBy] ?? 'users.created_at', $sortOrder);

        return ApiResponse::paginated($q->paginate($limit));
    }

    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(User::with([
            'empleado.puestoActual.puesto', 'empleado.tratamiento', 'roles',
        ])->findOrFail($id));
    }

    public function store(RegisterUserRequest $request, UserService $users): JsonResponse
    {
        return ApiResponse::created(
            $users->create($request->validated(), $request->user())
        );
    }

    public function update(UpdateUserRequest $request, string $id, UserService $users): JsonResponse
    {
        $user = User::findOrFail($id);
        $data = $request->validated();

        if (isset($data['role']) && $data['role'] !== $user->role) {
            $user->syncRoles([$data['role']]);
        }
        // canBackfill otorgado: marca el día de habilitación
        if (($data['canBackfill'] ?? false) && ! $user->canBackfill) {
            $data['canBackfillEnabledAt'] = now('UTC')->toDateString();
        }

        // Vinculación a empleado: los nombres de la cuenta se copian de la
        // ficha de RRHH (el empleado manda sobre los nombres propios).
        if (array_key_exists('empleadoId', $data) && $data['empleadoId']) {
            $empleado = Empleado::findOrFail($data['empleadoId']);
            $data['firstName'] = $empleado->nombre;
            $data['lastName'] = $empleado->apellidos;
            $data['paternalSurname'] = null;
            $data['maternalSurname'] = null;
        }

        $user->update($data);

        return ApiResponse::ok($user->fresh(['empleado.puestoActual.puesto', 'empleado.tratamiento', 'roles']));
    }

    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['isActive' => false]);

        return ApiResponse::ok(['message' => 'Usuario desactivado.']);
    }

    public function resetPassword(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $temporary = Str::random(10);
        $user->update(['password' => $temporary]);

        // (A8) El reseteo por ADMIN revoca TODOS los tokens del usuario reseteado.
        $user->tokens()->delete();

        return ApiResponse::ok(['temporaryPassword' => $temporary]);
    }
}
