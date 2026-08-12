<?php

namespace Database\Factories;

use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Movimiento>
 */
class MovimientoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Nota: crear un movimiento por factory no recalcula el `saldo_actual` de su cuenta — eso solo
     * ocurre al pasar por TesoreriaService. Sirve para probar lecturas (filtros, listados); para
     * probar saldos hay que registrar el movimiento por la API o por el servicio.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cuenta_id' => Cuenta::factory(),
            'tipo' => TipoMovimiento::Ingreso->value,
            'monto' => 100.00,
            'fecha' => now()->toDateString(),
            'concepto' => $this->faker->sentence(3),
        ];
    }
}
