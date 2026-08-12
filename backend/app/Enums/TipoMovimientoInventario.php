<?php

namespace App\Enums;

/**
 * Las tres —y únicas— operaciones sobre el par (existencia, faltante_pendiente) de un artículo
 * (ver 017-inventario.md).
 *
 * `entrada` y `salida` mueven una cantidad relativa; `ajuste` **fija** la cantidad final capturada
 * por el usuario. Esa diferencia es la razón por la que el alta manual de un artículo al inventario
 * es un `ajuste` y no una `entrada`: el usuario declara un total, no un incremento.
 */
enum TipoMovimientoInventario: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';
    case Ajuste = 'ajuste';

    public function texto(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
            self::Ajuste => 'Ajuste',
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
