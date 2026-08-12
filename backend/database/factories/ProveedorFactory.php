<?php

namespace Database\Factories;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PhpCfdi\Rfc\RfcFaker;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
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
            'nombre_comercial' => $this->faker->company(),
            'nombre_contacto' => $this->faker->optional()->name(),
            'correo' => $this->faker->optional()->companyEmail(),
            'telefono' => $this->faker->optional()->passthrough('+52'.$this->faker->numerify('##########')),
            'rfc' => $this->faker->optional()->passthrough((new RfcFaker)->mexicanRfcMoral()),
        ];
    }
}
