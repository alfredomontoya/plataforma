<?php

namespace Tests;

use App\Models\Empleado;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function empleadoDemo(array $attributes = []): Empleado
    {
        return Empleado::factory()->create($attributes);
    }

    protected function userFor(string $username, string $role, array $user = [], array $empleado = []): User
    {
        $empleadoModel = $this->empleadoDemo($empleado);

        return app(UserService::class)->create(array_merge([
            'username' => $username,
            'password' => 'password',
            'email' => "{$username}@test.local",
            'role' => $role,
            'empleadoId' => $empleadoModel->id,
        ], $user));
    }
}
