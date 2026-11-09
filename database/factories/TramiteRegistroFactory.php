<?php

namespace Database\Factories;

use App\Models\TramiteRegistro;
use App\Models\User;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class TramiteRegistroFactory extends Factory
{
    protected $model = TramiteRegistro::class;

    public function definition(): array
    {
        return [
            'userId' => fn () =>
                User::query()->inRandomOrder()->value('id')
                ?? User::factory(),

            'serviceId' => fn () =>
                Service::query()->where('isActive', true)->inRandomOrder()->value('id')
                ?? Service::factory(),

            'date' => fake()->dateTimeBetween('-30 days', 'now'),

            'placa' => fake()->numerify('####') .
                fake()->regexify('[A-Z]{3}'),

            'observaciones' => fake()->optional(0.30)->sentence(),

            'ticket' => null,

            'estado' => 'procesado',

            'activo' => true,
        ];
    }
}
