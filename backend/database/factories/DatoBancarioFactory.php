<?php

namespace Database\Factories;

use App\Models\DatoBancario;
use App\Rules\ClabeValida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatoBancario>
 */
class DatoBancarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre_banco' => $this->faker->randomElement(['BBVA', 'Santander', 'Banorte', 'HSBC']),
            'beneficiario' => $this->faker->name(),
            'numero_cuenta' => (string) $this->faker->numerify('##########'),
            'tarjeta' => (string) $this->faker->numerify('################'),
            'clabe' => self::clabeValida(),
            'visible_en_cotizaciones' => true,
            'orden' => 1,
        ];
    }

    public function oculto(): static
    {
        return $this->state(fn () => ['visible_en_cotizaciones' => false]);
    }

    /**
     * CLABE de 18 dígitos con el verificador que le toca. Una CLABE al azar sería rechazada por
     * `ClabeValida` y la factory no serviría para probar el camino feliz.
     */
    public static function clabeValida(?string $primeros17 = null): string
    {
        $primeros17 ??= str_pad((string) random_int(0, 99999999999999999), 17, '0', STR_PAD_LEFT);

        return $primeros17.ClabeValida::digitoVerificador($primeros17);
    }
}
