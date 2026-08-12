<?php

namespace Database\Factories;

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrdenCompra>
 */
class OrdenCompraFactory extends Factory
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
            'proveedor_id' => Proveedor::factory(),
            'folio' => $this->faker->unique()->numberBetween(1, 100000),
            'estado' => EstadoOrdenCompra::Borrador->value,
            'subtotal' => 200.00,
            'total_descuento' => 0,
            'total_iva_16' => 32.00,
            'total_iva_0' => 0,
            'total_exento' => 0,
            'total' => 232.00,
        ];
    }
}
