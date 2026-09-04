<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_raiz_redirige_a_registro(): void
    {
        $this->get('/')->assertRedirect('/operador/entrada');
    }

    public function test_login_renderiza_inertia(): void
    {
        $this->get('/login')->assertOk();
    }
}
