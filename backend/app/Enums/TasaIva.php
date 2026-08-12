<?php

namespace App\Enums;

/**
 * Tasa de IVA seleccionable por línea de factura (ver 007-facturacion.md, supuesto #5).
 */
enum TasaIva: string
{
    case General = '16';
    case Cero = '0';
    case Exento = 'exento';

    public function texto(): string
    {
        return match ($this) {
            self::General => '16% (tasa general)',
            self::Cero => '0%',
            self::Exento => 'Exento',
        };
    }

    /**
     * Tasa numérica a aplicar sobre el importe neto de la línea (0 para "exento").
     */
    public function tasa(): float
    {
        return match ($this) {
            self::General => 0.16,
            self::Cero => 0.0,
            self::Exento => 0.0,
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
