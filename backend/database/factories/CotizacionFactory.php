<?php

namespace Database\Factories;

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cotizacion>
 */
class CotizacionFactory extends Factory
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
            'cliente_id' => Cliente::factory(),
            'folio' => $this->faker->unique()->numberBetween(1, 100000),
            'estado' => EstadoCotizacion::Borrador->value,
            'subtotal' => 200.00,
            'total_descuento' => 0,
            'total_iva_16' => 32.00,
            'total_iva_0' => 0,
            'total_exento' => 0,
            'total' => 232.00,
        ];
    }
}
