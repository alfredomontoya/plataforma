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
use Laravel\Sanctum\PersonalAccessToken;

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

        // El nombre del token identifica al dispositivo en la lista de sesiones.
        // No se revocan tokens previos: se permite usar varios equipos a la vez.
        $token = $user->createToken($this->deviceName($request));

        return ApiResponse::ok([
            'accessToken' => $token->plainTextToken,
            'user' => $user->load(['roles', 'empleado.puestoActual.puesto']),
            'otherSessions' => $this->otherSessionsCount($user, $token->accessToken->id),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $request->user()->currentAccessToken();
        $currentToken = $current instanceof PersonalAccessToken ? $current : null;
        // Al rotar el token se conserva el nombre del dispositivo.
        $name = $currentToken?->name ?: $this->deviceName($request);
        $currentToken?->delete();
        $token = $user->createToken($name);

        return ApiResponse::ok([
            'accessToken' => $token->plainTextToken,
            'user' => $user->load(['roles', 'empleado.puestoActual.puesto']),
            'otherSessions' => $this->otherSessionsCount($user, $token->accessToken->id),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::ok(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok($request->user()->load(['roles', 'permissions', 'empleado.puestoActual.puesto']));
    }

    /**
     * Lista las sesiones/dispositivos activos del usuario. Cada token de
     * Sanctum representa un equipo o navegador.
     */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $this->currentTokenId($request);

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'device' => $token->name && $token->name !== 'api' ? $token->name : 'Dispositivo desconocido',
                'createdAt' => $token->created_at?->toIso8601String(),
                'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                'current' => $currentId !== null && $token->id === $currentId,
            ])
            ->values();

        return ApiResponse::ok($sessions);
    }

    /**
     * Cierra una sesión remota concreta. La sesión actual se cierra con logout.
     */
    public function destroySession(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $currentId = $this->currentTokenId($request);
        $token = $user->tokens()->find($id);

        if (! $token) {
            return ApiResponse::error('Sesión no encontrada.', 404);
        }

        if ($currentId !== null && $token->id === $currentId) {
            return ApiResponse::error('No puedes cerrar la sesión actual desde aquí. Usa «Cerrar sesión».', 400);
        }

        $token->delete();

        return ApiResponse::ok([
            'message' => 'Sesión cerrada.',
            'otherSessions' => $this->otherSessionsCount($user, $currentId),
        ]);
    }

    /**
     * Cierra todas las sesiones excepto la que está haciendo la petición.
     */
    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $this->currentTokenId($request);

        $user->tokens()
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        return ApiResponse::ok([
            'message' => 'Sesiones cerradas.',
            'otherSessions' => 0,
        ]);
    }

    public function register(RegisterUserRequest $request, UserService $users): JsonResponse
    {
        $user = $users->create($request->validated(), $request->user());

        return ApiResponse::created($user->load(['roles', 'empleado.puestoActual.puesto']));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            return ApiResponse::error('La contraseña actual es incorrecta.', 400);
        }

        $user->update(['password' => $data['newPassword']]);

        // (A8) Al cambiar la contraseña se revocan los demás tokens del usuario
        // (se conserva el token con el que se autenticó esta petición).
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

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

        // (A8) El reseteo por ADMIN revoca TODOS los tokens del usuario reseteado.
        $user->tokens()->delete();

        return ApiResponse::ok(['temporaryPassword' => $temporary]);
    }

    private function currentTokenId(Request $request): ?int
    {
        $token = $request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }

    private function otherSessionsCount(User $user, ?int $currentId): int
    {
        return $user->tokens()
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->count();
    }

    /**
     * Nombre legible del dispositivo a partir del User-Agent. Se guarda en
     * `personal_access_tokens.name`; nunca se usa para autorizar.
     */
    private function deviceName(Request $request): string
    {
        $ua = trim((string) $request->userAgent());

        if ($ua === '') {
            return 'Dispositivo desconocido';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} · {$os}" : $browser;
    }
}
