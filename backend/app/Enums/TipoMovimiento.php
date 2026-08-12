<?php

namespace App\Enums;

/**
 * Clasifica un Movimiento de Tesorería y determina cómo su `monto` afecta el saldo de la cuenta
 * (ver 010-tesoreria.md).
 *
 * `ingreso` y `egreso` guardan siempre un monto positivo: el signo lo pone el tipo. `ajuste` y
 * `transferencia` guardan el monto ya firmado — el ajuste según corrija de más o de menos, y la
 * transferencia porque sus dos filas comparten tipo (negativa en la cuenta origen, positiva en la
 * destino) y por lo tanto el tipo no puede determinar el signo.
 */
enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Egreso = 'egreso';
    case Transferencia = 'transferencia';
    case Ajuste = 'ajuste';

    public function texto(): string
    {
        return match ($this) {
            self::Ingreso => 'Ingreso',
            self::Egreso => 'Egreso',
            self::Transferencia => 'Transferencia',
            self::Ajuste => 'Ajuste',
        };
    }

    /**
     * Efecto que un monto de este tipo tiene sobre el saldo de la cuenta.
     */
    public function efectoEnSaldo(float $monto): float
    {
        return match ($this) {
            self::Ingreso => abs($monto),
            self::Egreso => -abs($monto),
            self::Ajuste, self::Transferencia => $monto,
        };
    }

    /**
     * @return array<int, array{id: string, texto: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $caso) => ['id' => $caso->value, 'texto' => $caso->texto()],
            self::cases(),
        );
    }
}
