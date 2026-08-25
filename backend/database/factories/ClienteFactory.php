<?php

namespace Database\Factories;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PhpCfdi\Rfc\RfcFaker;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
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
            'rfc' => (new RfcFaker)->mexicanRfcMoral(),
            'razon_social' => $this->faker->company(),
            'regimen_fiscal' => '601',
            'codigo_postal_fiscal' => '20000',
            'nombre_comercial' => $this->faker->optional()->company(),
            'correo_contacto' => $this->faker->optional()->companyEmail(),
            'telefono' => $this->faker->optional()->numerify('##########'),
            'direccion_comercial' => $this->faker->optional()->streetAddress(),
            // Sin descuento por defecto, para que los tests de 004/007/008 no cambien de resultado
            // al aparecer la columna (ver 015-descuento-permanente-cliente.md).
            'descuento_permanente' => 0,
            // No distribuidor por defecto, mismo criterio que descuento_permanente (ver
            // 033-precio-distribuidor.md).
            'es_distribuidor' => false,
        ];
    }

    public function personaFisica(): static
    {
        return $this->state(fn () => [
            'rfc' => (new RfcFaker)->mexicanRfcFisica(),
            'regimen_fiscal' => '612',
        ]);
    }
}
