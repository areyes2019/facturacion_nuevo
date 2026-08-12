<?php

namespace App\Enums;

/**
 * Catálogo SAT c_ObjetoImp (CFDI 4.0). Solo 4 valores fijos y estables, por lo que se
 * hardcodea como enum en vez de agregarse a la base sqlite de catálogos SAT
 * (ver 006-gestion-articulos.md).
 */
enum ObjetoImpuesto: string
{
    case NoObjeto = '01';
    case SiObjeto = '02';
    case SiObjetoNoDesglose = '03';
    case SiObjetoNoCausaImpuesto = '04';

    public function texto(): string
    {
        return match ($this) {
            self::NoObjeto => 'No objeto de impuesto',
            self::SiObjeto => 'Sí objeto de impuesto',
            self::SiObjetoNoDesglose => 'Sí objeto del impuesto y no obligado al desglose',
            self::SiObjetoNoCausaImpuesto => 'Sí objeto del impuesto y no causa impuesto',
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
