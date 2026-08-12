<?php

namespace App\Enums;

/**
 * Tipo de cuenta de Tesorería. Solo 4 valores fijos, hardcodeados como enum sin catálogo ni
 * endpoint propio (mismo patrón que MetodoPago y ObjetoImpuesto): los mismos 4 valores viven
 * embebidos en el frontend (ver 010-tesoreria.md).
 */
enum TipoCuenta: string
{
    case Efectivo = 'efectivo';
    case Banco = 'banco';
    case Digital = 'digital';
    case Otro = 'otro';

    public function texto(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Banco => 'Banco',
            self::Digital => 'Digital',
            self::Otro => 'Otro',
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
