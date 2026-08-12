<?php

namespace App\Enums;

/**
 * Método de pago CFDI (PUE/PPD). Solo 2 valores fijos y estables, por lo que se hardcodea como
 * enum en vez de agregarse a la base sqlite de catálogos SAT (ver 007-facturacion.md).
 */
enum MetodoPago: string
{
    case Pue = 'PUE';
    case Ppd = 'PPD';

    public function texto(): string
    {
        return match ($this) {
            self::Pue => 'Pago en una sola exhibición (PUE)',
            self::Ppd => 'Pago en parcialidades o diferido (PPD)',
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
