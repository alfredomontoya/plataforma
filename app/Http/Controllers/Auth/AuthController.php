<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Auth con Sanctum: Bearer para /api/* + sesión web para Inertia (docs 01/04/05).
 * Refresh = rotar token. Error de login genérico (no revela si el usuario existe).
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->validated()['username'])->first();

        if (! $user || ! Hash::check($request->validated()['password'], $user->password) || ! $user->isActive) {
            return ApiResponse::error('Credenciales inválidas.', 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::ok([
            'accessToken' => $token,
            'user' => $user->load(['activePosition', 'roles']),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        $token = $user->createToken('api')->plainTextToken;

        return ApiResponse::ok([
            'accessToken' => $token,
            'user' => $user->load(['activePosition', 'roles']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::ok(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($request->user()->load(['activePosition', 'roles', 'permissions']));
    }

    public function register(RegisterUserRequest $request, UserService $users): JsonResponse
    {
        $user = $users->createWithPosition($request->validated());

        return ApiResponse::created($user->load(['activePosition', 'roles']));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            return ApiResponse::error('La contraseña actual es incorrecta.', 400);
        }

        $user->update(['password' => $data['newPassword']]);

        return ApiResponse::ok(['message' => 'Contraseña actualizada.']);
    }

    /**
     * Solo ADMIN. Genera una temporal y la devuelve una única vez.
     */
    public function resetPassword(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $temporary = Str::random(10);
        $user->update(['password' => $temporary]);

        return ApiResponse::ok(['temporaryPassword' => $temporary]);
    }
}
