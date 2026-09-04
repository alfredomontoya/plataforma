<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gestión de usuarios — todo solo ADMIN (contrato 04 § Usuarios).
 */
class UserController extends Controller
{
    private const SORTS = [
        'username' => 'users.username', 'firstName' => 'users.firstName',
        'role' => 'users.role', 'title' => 'users.title',
        'isActive' => 'users.isActive', 'createdAt' => 'users.created_at',
        'position' => 'positions.position',
    ];

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->query('sortBy', 'createdAt');
        $sortOrder = strtolower((string) $request->query('sortOrder', 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        $q = User::query()->with('activePosition')->select('users.*')
            ->leftJoin('positions', 'positions.id', '=', 'users.activePositionId');

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('users.username', 'like', "%{$search}%")
                ->orWhere('users.firstName', 'like', "%{$search}%")
                ->orWhere('users.lastName', 'like', "%{$search}%"));
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
        return ApiResponse::ok(User::with(['activePosition', 'roles'])->findOrFail($id));
    }

    public function store(RegisterUserRequest $request, UserService $users): JsonResponse
    {
        return ApiResponse::created(
            $users->createWithPosition($request->validated())->load(['activePosition', 'roles'])
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
        $user->update($data);

        return ApiResponse::ok($user->fresh(['activePosition', 'roles']));
    }

    public function updatePosition(UpdatePositionRequest $request, string $id, UserService $users): JsonResponse
    {
        $position = $users->changePosition(User::findOrFail($id), $request->validated());

        return ApiResponse::ok($position);
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

        return ApiResponse::ok(['temporaryPassword' => $temporary]);
    }
}
