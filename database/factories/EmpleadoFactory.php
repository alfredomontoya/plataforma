<?php

namespace Database\Factories;

use App\Models\Empleado;
use App\Models\Tratamiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Empleado>
 */
class EmpleadoFactory extends Factory
{
    protected $model = Empleado::class;

    public function definition(): array
    {
        $faker = fake('es_ES');

        return [
            'tratamientoId' => null,
            'ci' => fake()->unique()->numerify('########'),
            'nombre' => $faker->firstName(),
            'apellidos' => $faker->lastName().' '.$faker->lastName(),
            'fechaNacimiento' => fake()->dateTimeBetween('-60 years', '-22 years')->format('Y-m-d'),
            'telefono' => fake()->numerify('7#######'),
            'direccion' => $faker->address(),
            'profesion' => $faker->jobTitle(),
            'estado' => Empleado::ESTADO_ACTIVO,
            'creadoPorEmpleadoId' => null,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['estado' => Empleado::ESTADO_INACTIVO]);
    }

    public function conTratamiento(): static
    {
        return $this->state(fn () => [
            'tratamientoId' => Tratamiento::query()->where('activo', true)->inRandomOrder()->value('id'),
        ]);
    }
}
