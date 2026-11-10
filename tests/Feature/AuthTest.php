<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(string $username, string $role, string $password = 'password'): User
    {
        return app(UserService::class)->create([
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'firstName' => 'Test',
            'lastName' => 'User',
        ]);
    }

    public function test_login_invalido_devuelve_401_con_envelope(): void
    {
        $this->makeUser('admin1', 'ADMIN');

        $res = $this->postJson('/api/auth/login', ['username' => 'admin1', 'password' => 'xxx']);

        $res->assertStatus(401)->assertJson(['success' => false]);
    }

    public function test_login_sin_campos_devuelve_400_en_espanol(): void
    {
        $res = $this->postJson('/api/auth/login', []);

        $res->assertStatus(400)->assertJson(['success' => false]);
    }

    public function test_flujo_login_me_refresh_logout(): void
    {
        $this->makeUser('jefe1', 'JEFE');

        $login = $this->postJson('/api/auth/login', ['username' => 'jefe1', 'password' => 'password']);
        $login->assertOk()->assertJson(['success' => true]);
        $token = $login->json('data.accessToken');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJson(['success' => true]);

        $refresh = $this->withToken($token)->postJson('/api/auth/refresh');
        $refresh->assertOk();
        $newToken = $refresh->json('data.accessToken');
        $this->assertNotSame($token, $newToken);

        // token viejo revocado (forgetGuards: el guard singleton cachea
        // el usuario entre requests en tests; en producción cada HTTP es fresco)
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);

        $this->withToken($newToken)->postJson('/api/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($newToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_login_en_varios_equipos_no_bloquea_y_devuelve_otras_sesiones(): void
    {
        $this->makeUser('multi1', 'JEFE');

        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

        $first = $this->withHeaders(['User-Agent' => $chrome])
            ->postJson('/api/auth/login', ['username' => 'multi1', 'password' => 'password']);
        $first->assertOk()->assertJsonPath('data.otherSessions', 0);
        $token1 = $first->json('data.accessToken');

        $second = $this->withHeaders(['User-Agent' => $safari])
            ->postJson('/api/auth/login', ['username' => 'multi1', 'password' => 'password']);
        $second->assertOk()->assertJsonPath('data.otherSessions', 1);
        $token2 = $second->json('data.accessToken');

        // Ninguno de los dos logins bloquea al otro.
        Auth::forgetGuards();
        $this->withToken($token1)->getJson('/api/auth/me')->assertOk();

        Auth::forgetGuards();
        $list = $this->withToken($token2)->getJson('/api/auth/sessions');
        $list->assertOk()->assertJsonCount(2, 'data');

        $devices = collect($list->json('data'))->pluck('device');
        $this->assertTrue($devices->contains('Chrome · Windows'));
        $this->assertTrue($devices->contains('Safari · macOS'));

        $current = collect($list->json('data'))->firstWhere('current', true);
        $this->assertNotNull($current);
        $this->assertSame('Safari · macOS', $current['device']);
    }

    public function test_refresh_conserva_el_nombre_del_dispositivo(): void
    {
        $this->makeUser('refresh1', 'JEFE');
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';

        $login = $this->withHeaders(['User-Agent' => $ua])
            ->postJson('/api/auth/login', ['username' => 'refresh1', 'password' => 'password']);
        $token = $login->json('data.accessToken');

        $refresh = $this->withToken($token)->postJson('/api/auth/refresh');
        $refresh->assertOk()->assertJsonPath('data.otherSessions', 0);
        $newToken = $refresh->json('data.accessToken');

        Auth::forgetGuards();
        $this->withToken($newToken)->getJson('/api/auth/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.device', 'Chrome · Linux')
            ->assertJsonPath('data.0.current', true);
    }

    public function test_no_permite_cerrar_la_sesion_actual_ni_una_ajena(): void
    {
        $user = $this->makeUser('sess1', 'JEFE');
        $other = $this->makeUser('sess2', 'JEFE');

        $login = $this->postJson('/api/auth/login', ['username' => 'sess1', 'password' => 'password']);
        $token = $login->json('data.accessToken');

        $currentId = $user->tokens()->first()->id;

        // La sesión actual se cierra con logout, no con este endpoint.
        $this->withToken($token)->deleteJson("/api/auth/sessions/{$currentId}")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        // No se puede revocar un token de otro usuario.
        $this->postJson('/api/auth/login', ['username' => 'sess2', 'password' => 'password'])->assertOk();
        $otherId = $other->tokens()->first()->id;

        $this->withToken($token)->deleteJson("/api/auth/sessions/{$otherId}")
            ->assertStatus(404)
            ->assertJson(['success' => false]);

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_cerrar_todas_las_demas_conserva_la_actual(): void
    {
        $this->makeUser('sess3', 'JEFE');

        $first = $this->postJson('/api/auth/login', ['username' => 'sess3', 'password' => 'password']);
        $token1 = $first->json('data.accessToken');
        $second = $this->postJson('/api/auth/login', ['username' => 'sess3', 'password' => 'password']);
        $token2 = $second->json('data.accessToken');

        $this->withToken($token2)->deleteJson('/api/auth/sessions/others')
            ->assertOk()
            ->assertJsonPath('data.otherSessions', 0);

        Auth::forgetGuards();
        $this->withToken($token2)->getJson('/api/auth/me')->assertOk();

        Auth::forgetGuards();
        $this->withToken($token1)->getJson('/api/auth/me')->assertStatus(401);

        Auth::forgetGuards();
        $this->withToken($token2)->getJson('/api/auth/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_register_solo_admin(): void
    {
        $admin = $this->makeUser('admin1', 'ADMIN');
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');

        $payload = [
            'username' => 'nuevo1', 'password' => 'password', 'email' => 'nuevo1@test.local',
            'role' => 'JEFE', 'firstName' => 'N', 'lastName' => 'U',
        ];

        $this->postJson('/api/auth/register', $payload)->assertStatus(401);
        $this->actingAs($op, 'sanctum')->postJson('/api/auth/register', $payload)
            ->assertStatus(403)->assertJson(['success' => false]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/auth/register', $payload);
        $res->assertCreated()->assertJson(['success' => true]);

        $created = User::where('username', 'nuevo1')->first();
        $this->assertSame('nuevo1@test.local', $created->email);
        $this->assertTrue($created->hasRole('JEFE'));
    }

    public function test_change_y_reset_password(): void
    {
        $admin = $this->makeUser('admin1', 'ADMIN');
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');

        $this->actingAs($op, 'sanctum')->postJson('/api/auth/change-password', [
            'currentPassword' => 'wrong', 'newPassword' => 'nueva123', 'confirmPassword' => 'nueva123',
        ])->assertStatus(400);

        $this->actingAs($op, 'sanctum')->postJson('/api/auth/change-password', [
            'currentPassword' => 'password', 'newPassword' => 'nueva123', 'confirmPassword' => 'nueva123',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['username' => 'ing1', 'password' => 'nueva123'])->assertOk();

        $res = $this->actingAs($admin, 'sanctum')->postJson("/api/auth/reset-password/{$op->id}");
        $res->assertOk();
        $this->assertNotEmpty($res->json('data.temporaryPassword'));
    }

    public function test_usuario_desactivado_no_puede_usar_su_token(): void
    {
        $op = $this->makeUser('inactivo1', 'OPERATOR_INGRESO');
        $token = $op->createToken('test')->plainTextToken;
        $op->update(['isActive' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);
        $this->assertSame(0, $op->tokens()->count());
    }

    public function test_cabeceras_de_seguridad_en_respuestas_web(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
