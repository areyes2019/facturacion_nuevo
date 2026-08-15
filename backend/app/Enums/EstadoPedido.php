<?php

namespace App\Enums;

/**
 * Máquina de estados de un Pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * Los tres primeros **no se escriben a mano**: se derivan de la suma de los pagos cada vez que se
 * agrega o se borra uno. Solo `Entregado` es una decisión, y la toma el escaneo del QR de la
 * etiqueta.
 */
enum EstadoPedido: string
{
    case Pendiente = 'pendiente';
    case Anticipo = 'anticipo';
    case Pagado = 'pagado';
    case Entregado = 'entregado';

    public function texto(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de pago',
            self::Anticipo => 'Con anticipo',
            self::Pagado => 'Pagado',
            self::Entregado => 'Entregado',
        };
    }

    /**
     * Editable mientras el ticket todavía no salió hacia el cliente. En `pagado` y `entregado` el
     * pedido queda congelado: el cliente ya tiene en su teléfono una imagen con esas líneas y esos
     * importes, y cambiarlas debajo de ella convertiría el ticket en un papel que no coincide con
     * nada.
     */
    public function esEditable(): bool
    {
        return in_array($this, [self::Pendiente, self::Anticipo], true);
    }

    /**
     * El estado que corresponde a un pedido según lo que lleva pagado. No decide sobre
     * `Entregado`: quien ya entregó no regresa a la escalera de pagos por haber borrado un pago.
     */
    public static function segunPagos(float $total, float $pagado): self
    {
        if ($pagado <= 0) {
            return self::Pendiente;
        }

        return $pagado >= $total ? self::Pagado : self::Anticipo;
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
