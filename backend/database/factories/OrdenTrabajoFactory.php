<?php

namespace Database\Factories;

use App\Enums\EstadoOrdenTrabajo;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenTrabajo>
 */
class OrdenTrabajoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'folio' => $this->faker->unique()->numberBetween(1, 100000),
            'estado' => EstadoOrdenTrabajo::Pendiente->value,
            'documentable_type' => Pedido::class,
            'documentable_id' => Pedido::factory(),
        ];
    }
}
