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
        return app(UserService::class)->createWithPosition([
            'username' => $username,
            'password' => $password,
            'role' => $role,
            'title' => 'LIC',
            'firstName' => 'Test',
            'lastName' => 'User',
            'position' => 'Cargo Test',
            'department' => 'Depto Test',
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

    public function test_register_solo_admin(): void
    {
        $admin = $this->makeUser('admin1', 'ADMIN');
        $op = $this->makeUser('ing1', 'OPERATOR_INGRESO');

        $payload = [
            'username' => 'nuevo1', 'password' => 'password', 'role' => 'JEFE',
            'title' => 'LIC', 'firstName' => 'N', 'lastName' => 'U',
            'position' => 'Cargo', 'department' => 'Depto',
        ];

        $this->postJson('/api/auth/register', $payload)->assertStatus(401);
        $this->actingAs($op, 'sanctum')->postJson('/api/auth/register', $payload)
            ->assertStatus(403)->assertJson(['success' => false]);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/auth/register', $payload);
        $res->assertCreated()->assertJson(['success' => true]);

        $created = User::where('username', 'nuevo1')->first();
        $this->assertNotNull($created->activePositionId);
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
}
