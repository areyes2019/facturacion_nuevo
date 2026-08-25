<?php

namespace Database\Factories;

use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use App\Models\Envio;
use App\Models\OrdenTrabajo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Envio>
 */
class EnvioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'documentable_type' => OrdenTrabajo::class,
            'documentable_id' => OrdenTrabajo::factory(),
            'nombre_receptor' => $this->faker->name(),
            'telefono_receptor' => $this->faker->numerify('55########'),
            'direccion' => $this->faker->streetAddress(),
            'fecha_recepcion' => now()->toDateString(),
            'hora_recepcion' => '12:00',
            'tarifa' => TarifaEnvio::A->value,
            'monto' => 50.00,
            'forma_pago' => FormaPagoEnvio::PorCobrar->value,
        ];
    }
}
