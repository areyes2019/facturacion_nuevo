<?php

namespace Database\Factories;

use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalogo>
 */
class CatalogoFactory extends Factory
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
            'nombre' => $this->faker->unique()->words(2, true),
            'descuento' => 0,
            'utilidad_porcentaje' => 0,
            'utilidad_distribuidor_porcentaje' => 0,
        ];
    }
}
