<?php

namespace Database\Factories;

use App\Enums\EstadoFactura;
use App\Enums\MetodoPago;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Factura>
 */
class FacturaFactory extends Factory
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
            'estado' => EstadoFactura::Pendiente->value,
            'uso_cfdi' => 'G03',
            'forma_pago' => '03',
            'metodo_pago' => MetodoPago::Pue->value,
            'subtotal' => 200.00,
            'total_descuento' => 0,
            'total_iva_16' => 32.00,
            'total_iva_0' => 0,
            'total_exento' => 0,
            'total' => 232.00,
        ];
    }
}
