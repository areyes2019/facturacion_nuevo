<?php

namespace Database\Factories;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
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
            'cliente_nombre' => $this->faker->name(),
            'cliente_telefono' => $this->faker->numerify('55########'),
            'cliente_correo' => $this->faker->safeEmail(),
            'estado' => EstadoPedido::Pendiente->value,
            'subtotal' => 200.00,
            'total_descuento' => 0,
            'total_iva_16' => 32.00,
            'total_iva_0' => 0,
            'total_exento' => 0,
            'total' => 232.00,
        ];
    }
}
