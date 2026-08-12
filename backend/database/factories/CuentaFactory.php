<?php

namespace Database\Factories;

use App\Enums\TipoCuenta;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $saldoInicial = 0;

        return [
            'user_id' => User::factory(),
            'nombre' => $this->faker->unique()->words(2, true),
            'tipo' => TipoCuenta::Banco->value,
            'saldo_inicial' => $saldoInicial,
            // Una cuenta recién creada no tiene movimientos, así que ambos saldos coinciden.
            'saldo_actual' => $saldoInicial,
            'activa' => true,
        ];
    }

    public function conSaldo(float $saldoInicial): static
    {
        return $this->state(fn () => [
            'saldo_inicial' => $saldoInicial,
            'saldo_actual' => $saldoInicial,
        ]);
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activa' => false]);
    }
}
