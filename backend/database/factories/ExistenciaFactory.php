<?php

namespace Database\Factories;

use App\Models\Articulo;
use App\Models\Existencia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Existencia>
 */
class ExistenciaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'articulo_id' => Articulo::factory(),
            'existencia' => 0,
            'faltante_pendiente' => 0,
            'minimo' => 0,
            'maximo' => null,
        ];
    }
}
